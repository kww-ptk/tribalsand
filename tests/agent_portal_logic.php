<?php
declare(strict_types=1);
// Travel-agent portal — discount resolution + net pricing (pure), plus a DB
// round-trip (agent row incl. JSONB venue overrides, password hash) inside ONE
// transaction that is rolled back. Run: php tests/agent_portal_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/agent.php';
require_once __DIR__ . '/../includes/bookings.php';   // bookings_sync_hold() for the ledger check
require_once __DIR__ . '/../includes/mail.php';       // _hold_notification_html() for the trade-row check

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

// ── Stay validation (pure) ─────────────────────────────────────────────────────
check('stay: a normal future window is accepted and normalised',
    agent_valid_stay('2098-6-10', '2098-06-12', '2026-09-15') === ['2098-06-10', '2098-06-12', 2]);
check('stay: a check-in before today is refused',  agent_valid_stay('2026-09-14', '2026-09-16', '2026-09-15') === null);
check('stay: today is allowed as a check-in',      agent_valid_stay('2026-09-15', '2026-09-16', '2026-09-15') !== null);
check('stay: reversed / equal dates are refused',  agent_valid_stay('2098-06-12', '2098-06-10', '2026-09-15') === null && agent_valid_stay('2098-06-10', '2098-06-10', '2026-09-15') === null);
check('stay: garbage is refused',                  agent_valid_stay('soon', '2098-06-12', '2026-09-15') === null);
check('stay: 30 nights ok, 31 refused',
    agent_valid_stay('2098-06-01', '2098-07-01', '2026-09-15') !== null && agent_valid_stay('2098-06-01', '2098-07-02', '2026-09-15') === null);

// ── Pricing a configurations result (pure) ────────────────────────────────────
$cfg = [
    'singles' => [['slug' => 'a', 'name' => 'A', 'total' => 1000.0, 'currency' => 'USD']],
    'entire'  => [['slug' => 'whole', 'name' => 'Whole', 'total' => 5000.0, 'currency' => 'USD']],
    'combos'  => [['rooms' => [['slug' => 'a', 'units_used' => 2, 'total' => 2000.0, 'currency' => 'USD']], 'total' => 2000.0, 'currency' => 'USD', 'capacity' => 4]],
    'max_capacity' => 8,
];
$priced = agent_price_configurations($cfg, ['discount_pct' => 10, 'venue_discounts' => [7 => 25]], 3);
check('price: net_total on a single = published × 0.9',   eq($priced['singles'][0]['net_total'], 900.0));
check('price: net_total on the whole property',          eq($priced['entire'][0]['net_total'], 4500.0));
check('price: net_total on a combo and on its rooms',    eq($priced['combos'][0]['net_total'], 1800.0) && eq($priced['combos'][0]['rooms'][0]['net_total'], 1800.0));
check('price: published totals are untouched',           eq($priced['singles'][0]['total'], 1000.0) && eq($priced['combos'][0]['total'], 2000.0));
check('price: discount_pct is reported',                 eq($priced['discount_pct'], 10.0));
$pricedOv = agent_price_configurations($cfg, ['discount_pct' => 10, 'venue_discounts' => [7 => 25]], 7);
check('price: a per-venue override drives the net',      eq($pricedOv['singles'][0]['net_total'], 750.0));
$priced0 = agent_price_configurations($cfg, ['discount_pct' => 0], 3);
check('price: 0% leaves net = published',                eq($priced0['singles'][0]['net_total'], 1000.0));

// ── Combo link parameter round-trip (pure) ────────────────────────────────────
check('rooms param: builds from combo rooms',
    agent_rooms_param([['slug' => 'double', 'units_used' => 2], ['slug' => 'bunk', 'units_used' => 1]]) === 'double:2,bunk:1');
check('rooms param: parses back, clamps and drops junk',
    agent_parse_rooms_param('double:2,bunk,bad slug!,x:99') === [['slug' => 'double', 'units' => 2], ['slug' => 'bunk', 'units' => 1], ['slug' => 'x', 'units' => 8]]);

// ── Trade lines (pure) ────────────────────────────────────────────────────────
$tl = agent_trade_lines(['name' => 'Jane Agent', 'agency' => 'Safari Co', 'email' => 'jane@x.com'],
    ['nights' => 2, 'published' => 1000.0, 'net' => 850.0, 'currency' => 'USD', 'discount_pct' => 15]);
check('trade lines: agent line names agency, agent and email', $tl['agent'] === 'Safari Co — Jane Agent <jane@x.com>');
check('trade lines: rate line shows net, nights and the discount', $tl['rate'] === 'USD 850 net · 2 nights · 15% off published USD 1,000');
$tl0 = agent_trade_lines(['name' => 'Solo', 'agency' => '', 'email' => 's@x.com'],
    ['nights' => 1, 'published' => 200.0, 'net' => 200.0, 'currency' => 'KES', 'discount_pct' => 0]);
check('trade lines: no discount reads as published rate', $tl0['agent'] === 'Solo <s@x.com>' && $tl0['rate'] === 'KES 200 · 1 night · published rate (no trade discount)');

// ── Request status (pure) ─────────────────────────────────────────────────────
$now = strtotime('2026-09-15 10:00:00');
check('status: no hold = enquiry sent', agent_request_status(['hold_status' => null], $now)['label'] === 'Enquiry sent');
$st = agent_request_status(['hold_status' => 'pending', 'expires_at' => '2026-09-15 21:30:00'], $now);
check('status: pending shows the countdown', $st['label'] === 'On hold' && $st['note'] === 'Expires in 11h 30m');
check('status: pending with no expiry awaits confirmation', agent_request_status(['hold_status' => 'pending', 'expires_at' => null], $now)['note'] === 'Awaiting confirmation');
check('status: confirmed / expired / cancelled labels',
    agent_request_status(['hold_status' => 'confirmed'], $now)['label'] === 'Confirmed'
    && agent_request_status(['hold_status' => 'expired'], $now)['label'] === 'Expired'
    && agent_request_status(['hold_status' => 'cancelled'], $now)['class'] === 'cancelled');

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

            // ── Quote parity + form-mode rule ───────────────────────────────
            $agentRow = db_query("SELECT * FROM travel_agents WHERE email = 'zz-agent@example.com'")->fetch();
            $qRoom = db_query(
                "SELECT r.* FROM rooms r JOIN venues v ON v.id = r.venue_id
                  WHERE r.is_published = TRUE AND v.is_published = TRUE AND r.price_amount > 0
                  ORDER BY r.id LIMIT 1")->fetch();
            if (!$qRoom) {
                echo "SKIP  no priced published room for the quote checks\n";
            } else {
                $canon = room_stay_quote((int)$qRoom['id'], (float)$qRoom['price_amount'], '2098-06-10', '2098-06-12');
                $aq    = agent_stay_quote($qRoom, $agentRow, '2098-06-10', '2098-06-12');
                check('quote: published = room_stay_quote() (the ONE path)', eq($aq['published'], (float)$canon['total']) && $aq['nights'] === 2);
                check('quote: net = published × (1 − 12.5%)',              eq($aq['net'], round((float)$canon['total'] * 0.875, 2)));
                check('quote: currency is the room’s',                      $aq['currency'] === ($qRoom['price_currency'] ?: 'USD'));
                $bad = agent_stay_quote($qRoom, $agentRow, '2098-06-12', '2098-06-10');
                check('quote: a reversed window is NOT a quote (nights 0, net 0)', $bad['nights'] === 0 && $bad['net'] === 0.0);

                check('form mode: the room’s own enquiry mode wins', agent_room_form_mode(array_merge($qRoom, ['form_mode' => 'enquiry'])) === 'enquiry');
                $hasUnits = count(fetch_units_by_room(room_inventory_room_id($qRoom))) > 0;
                check('form mode: availability only when the inventory room has units',
                    agent_room_form_mode(array_merge($qRoom, ['form_mode' => 'availability'])) === ($hasUnits ? 'availability' : 'enquiry'));
            }
        }
    } finally {
        db()->rollBack();
    }
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
