-- Tribal Sand: live sustainability metrics. Idempotent.
--
-- Figures shown on sustainability.php and the home page's "Live Data" cards.
-- Each row stores the last KNOWN-TRUE reading (value + baseline_at) plus an
-- optional accrual rate; the displayed number is derived at render time:
--
--     current = min(value + growth_per_day * days_since(baseline_at), max_value)
--
-- Nothing writes back to `value` on a schedule — re-entering a real reading in
-- Admin -> Sustainability re-bases the accrual instead of compounding on it.
CREATE TABLE IF NOT EXISTS sustainability_metrics (
    id             SERIAL PRIMARY KEY,
    metric_key     VARCHAR(60)  NOT NULL UNIQUE,
    label          VARCHAR(120) NOT NULL,
    value          NUMERIC(14,2) NOT NULL DEFAULT 0,
    baseline_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    growth_per_day NUMERIC(12,4) NOT NULL DEFAULT 0,
    max_value      NUMERIC(14,2),
    unit           VARCHAR(20)  NOT NULL DEFAULT '',
    decimals       SMALLINT     NOT NULL DEFAULT 2 CHECK (decimals BETWEEN 0 AND 4),
    note           VARCHAR(200) NOT NULL DEFAULT '',
    sort_order     INT          NOT NULL DEFAULT 0,
    is_published   BOOLEAN      NOT NULL DEFAULT TRUE,
    updated_at     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_by     INT
);
CREATE INDEX IF NOT EXISTS idx_sus_metrics_order ON sustainability_metrics (sort_order, id);

-- Seed the five figures the templates ship with. ON CONFLICT DO NOTHING so a
-- re-run never overwrites readings the owner has since corrected.
--
-- solar_mwh / co2_tonnes seed with growth_per_day = 0 deliberately: 27.59 MWh
-- reads as a 2024 annual total, and turning a published environmental claim into
-- an auto-incrementing counter is the owner's call to make, per metric.
-- beach_kg_total accrues at 30/7 kg/day, which restates the weekly rate the page
-- already publishes.
INSERT INTO sustainability_metrics
    (metric_key, label, value, growth_per_day, max_value, unit, decimals, note, sort_order)
VALUES
    ('solar_mwh',       'Solar Energy Generated',   27.59, 0,      NULL, 'MWh', 2, 'Tribal Dunes · updated weekly',   10),
    ('co2_tonnes',      'CO₂ Emissions Avoided',    21.88, 0,      NULL, 'T',   2, '= 1,503 trees equivalent',        20),
    ('beach_kg_total',  'Beach Waste Collected',   780.00, 4.2857, NULL, 'kg',  0, 'Cumulative · Bofa & Watamu',      30),
    ('beach_kg_weekly', 'Collected Every Week',     30.00, 0,      NULL, 'kg',  0, 'Per week · every week',           40),
    ('desal_pct',       'Desalinated Water',       100.00, 0,      100,  '%',   0, 'No freshwater depletion',         50)
ON CONFLICT (metric_key) DO NOTHING;
