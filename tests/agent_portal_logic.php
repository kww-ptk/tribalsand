<?php
declare(strict_types=1);
// Travel-agent portal — discount resolution + net pricing (pure), plus a DB
// round-trip (agent row incl. JSONB venue overrides, password hash) inside ONE
// transaction that is rolled back. Run: php tests/agent_portal_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/agent.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}
function eq(float $a, float $b): bool { return abs($a - $b) < 0.005; }

// ── Discount resolution (pure) ──────────────────────────────────────────────
check('discount: flat pct with no venue',
    eq(agent_discount_pct(['discount_pct' => 10]), 10.0));
check('discount: per-venue override (array) wins for that venue',
    eq(agent_discount_pct(['discount_pct' => 10, 'venue_discounts' => [3 => 20]], 3), 20.0));
check('discount: per-venue override (JSON string, as Postgres returns JSONB) wins',
    eq(agent_discount_pct(['discount_pct' => 10, 'venue_discounts' => '{"3":20}'], 3), 20.0));
check('discount: a venue with no override falls back to the flat pct',
    eq(agent_discount_pct(['discount_pct' => 10, 'venue_discounts' => [3 => 20]], 5), 10.0));
check('discount: clamps above 100',
    eq(agent_discount_pct(['discount_pct' => 150]), 100.0));
check('discount: clamps below 0',
    eq(agent_discount_pct(['discount_pct' => -5]), 0.0));

// ── Net price = published × (1 − discount), currency-agnostic (pure) ────────
check('net: 15% off 1000 = 850',
    eq(agent_net_price(1000, ['discount_pct' => 15]), 850.0));
check('net: 0% leaves the published price',
    eq(agent_net_price(1234.5, ['discount_pct' => 0]), 1234.5));
check('net: a per-venue override drives the net price for that venue',
    eq(agent_net_price(200, ['discount_pct' => 10, 'venue_discounts' => [7 => 25]], 7), 150.0));

// ── DB round-trip (rolled back) ─────────────────────────────────────────────
$hasDb = false;
try { db()->beginTransaction(); $hasDb = true; }
catch (Throwable $e) { echo "\nSKIP  no DB — agent row round-trip skipped\n"; }

if ($hasDb) {
    try {
        if (!agents_supported()) {
            echo "SKIP  travel_agents table absent — run add_travel_agents.sql\n";
        } else {
            check('db: holds.agent_id probe agrees with information_schema',
                holds_agent_supported() === (bool) db_query("SELECT 1 FROM information_schema.columns WHERE table_name='holds' AND column_name='agent_id'")->fetchColumn());
            db_query(
                "INSERT INTO travel_agents (name, agency, email, password_hash, discount_pct, venue_discounts)
                 VALUES ('ZZ Test Agent','ZZ Agency','zz-agent@example.com',:h,12.5,'{\"999\":20}')",
                [':h' => password_hash('secret123', PASSWORD_DEFAULT)]
            );
            $row = db_query("SELECT * FROM travel_agents WHERE email = 'zz-agent@example.com'")->fetch();
            check('db: the agent row round-trips', is_array($row) && $row['name'] === 'ZZ Test Agent');
            check('db: password verifies against the stored hash',
                password_verify('secret123', (string)$row['password_hash']));
            check('db: JSONB venue override resolves through agent_discount_pct',
                eq(agent_discount_pct($row, 999), 20.0));
            check('db: a non-overridden venue uses the flat 12.5%',
                eq(agent_discount_pct($row, 1), 12.5));
            check('db: net price at the overridden venue = 80 off 100',
                eq(agent_net_price(100, $row, 999), 80.0));
        }
    } finally {
        db()->rollBack();
    }
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
