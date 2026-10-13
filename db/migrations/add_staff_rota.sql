-- Staff rota (Oct 2026): the shift each person is PLANNED to work each day —
-- planned on Attendance → Week → "Plan the week". Separate from `attendance`
-- (what was actually worked), so the weekly grid can show plan against worked
-- and an approved shift swap can exchange two people's planned shifts.
-- The shift's times are copied onto the row, so changing a shift preset later
-- never rewrites an old plan. Run after add_hr_staff.sql. Idempotent.

CREATE TABLE IF NOT EXISTS staff_rota (
    id          SERIAL PRIMARY KEY,
    hr_staff_id INT  NOT NULL REFERENCES hr_staff(id) ON DELETE CASCADE,
    work_date   DATE NOT NULL,
    shift       TEXT NOT NULL,                 -- off | day | early | late | secday | secnight
    start_min   SMALLINT,                      -- minutes from midnight; NULL for off
    end_min     SMALLINT,                      -- ≥ 1440 when the shift ends the next morning
    note        TEXT,
    created_by  INT REFERENCES admin_users(id) ON DELETE SET NULL,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (hr_staff_id, work_date)
);
CREATE INDEX IF NOT EXISTS idx_staff_rota_date ON staff_rota (work_date);
