-- Staff room allocation (Oct 2026): which staff room / bed a team member is in,
-- per property, with the history of who was where.
--
--  • staff_rooms — a room in the staff accommodation of a property (or of no
--    property, e.g. the Watamu office house), with a number of beds.
--  • staff_room_allocations — a person in a room (and bed) from a date; date_to
--    is the day they moved out (NULL = still there). A person has at most ONE
--    current allocation; moving someone closes the old one the same day.
--
-- Run after add_hr_staff.sql. Idempotent.

CREATE TABLE IF NOT EXISTS staff_rooms (
    id          SERIAL PRIMARY KEY,
    venue_id    INT REFERENCES venues(id) ON DELETE SET NULL,
    label       TEXT        NOT NULL,                  -- "Staff block A · Room 3"
    beds        SMALLINT    NOT NULL DEFAULT 1 CHECK (beds BETWEEN 1 AND 20),
    notes       TEXT,
    is_active   BOOLEAN     NOT NULL DEFAULT TRUE,
    sort_order  INT         NOT NULL DEFAULT 0,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_staff_rooms_venue ON staff_rooms (venue_id, sort_order);

CREATE TABLE IF NOT EXISTS staff_room_allocations (
    id          SERIAL PRIMARY KEY,
    room_id     INT  NOT NULL REFERENCES staff_rooms(id) ON DELETE RESTRICT,
    hr_staff_id INT  NOT NULL REFERENCES hr_staff(id) ON DELETE CASCADE,
    bed         SMALLINT CHECK (bed IS NULL OR bed BETWEEN 1 AND 20),
    date_from   DATE NOT NULL,
    date_to     DATE,                                   -- moved out on (NULL = still there)
    note        TEXT,
    created_by  INT REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (date_to IS NULL OR date_to >= date_from)
);
CREATE INDEX IF NOT EXISTS idx_staff_room_alloc_room ON staff_room_allocations (room_id, date_to);
CREATE UNIQUE INDEX IF NOT EXISTS uq_staff_room_alloc_current
    ON staff_room_allocations (hr_staff_id) WHERE date_to IS NULL;
