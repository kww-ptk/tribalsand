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
$tlNo = agent_trade_lines(['name' => 'Solo', 'agency' => '', 'email' => 's@x.com'],
    ['nights' => 3, 'published' => 0.0, 'net' => 0.0, 'currency' => 'USD', 'discount_pct' => 15]);
check('trade lines: an unpriced room reads as on request, never USD 0', $tlNo['rate'] === 'Price on request · 3 nights · 15% trade discount applies');

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

// ── Trade rows in the staff hold email (pure HTML builder) ───────────────────
$mailBase = ['guest_name' => 'ZZ Traveller', 'guest_email' => 'a@x.com', 'room_name' => 'Suite', 'unit_name' => 'Unit A',
             'check_in' => '2098-06-10', 'check_out' => '2098-06-12', 'expires' => '24 hours',
             'confirm_url' => '#', 'decline_url' => '#', 'holds_url' => '#', 'has_tokens' => false];
$plain = _hold_notification_html($mailBase);
$trade = _hold_notification_html($mailBase + ['trade_agent' => 'Safari Co — Jane <j@x.com>', 'trade_rate' => 'USD 850 net · 2 nights']);
check('mail: a guest hold email has no trade rows', !str_contains($plain, 'Booked by'));
check('mail: a trade hold email names the agent and the net rate',
    str_contains($trade, 'Booked by') && str_contains($trade, 'Safari Co') && str_contains($trade, 'USD 850 net'));
check('mail: trade values are escaped', str_contains($trade, '&lt;j@x.com&gt;'));

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

            // ── Request writer round-trip (all rolled back) ──────────────────
            // A published, priced, individual, non-composite room with exactly ONE
            // active unit at a published property — so a second identical request
            // must be refused with a 409.
            $room = db_query(
                "SELECT r.* FROM rooms r JOIN venues v ON v.id = r.venue_id
                  WHERE r.is_published = TRUE AND v.is_published = TRUE AND r.is_entire_place = FALSE
                    AND r.price_amount > 0 AND r.slug NOT LIKE 'maya-ilai-%'
                    AND (SELECT COUNT(*) FROM units u WHERE u.room_id = r.id AND u.is_active) = 1
                  ORDER BY r.id LIMIT 1")->fetch();
            if (!$room) {
                echo "SKIP  no single-unit published room to book against\n";
            } else {
                db_query("UPDATE rooms SET form_mode = 'availability' WHERE id = :id", [':id' => $room['id']]);
                $venueSlug = (string) db_query('SELECT slug FROM venues WHERE id = :id', [':id' => $room['venue_id']])->fetchColumn();
                $ci = '2098-06-10'; $co = '2098-06-12';
                $req = ['kind' => 'room', 'room_slug' => $room['slug'], 'check_in' => $ci, 'check_out' => $co,
                        'adults' => 2, 'children' => 1, 'guest_name' => 'ZZ Traveller',
                        'guest_email' => 'zz-trav@example.com', 'guest_phone' => '+254700000000', 'notes' => 'Late arrival'];
                $res = agent_submit_request($agentRow, $req, ['source_page' => 'test', 'ip' => '127.0.0.1']);
                check('request: hold mode succeeds', $res['ok'] === true && $res['mode'] === 'hold' && (int)$res['hold_id'] > 0);

                $hold = db_query('SELECT * FROM holds WHERE id = :id', [':id' => $res['hold_id']])->fetch();
                check('request: hold names the traveller, is emailed to the agent',
                    $hold['guest_name'] === 'ZZ Traveller' && $hold['guest_email'] === 'zz-agent@example.com');
                check('request: hold is pending with a 24h expiry', $hold['status'] === 'pending' && !empty($hold['expires_at']));
                check('request: availability block written',
                    (bool) db_query('SELECT 1 FROM availability_blocks WHERE hold_id = :h', [':h' => $res['hold_id']])->fetchColumn());

                $canon     = room_stay_quote((int)$room['id'], (float)$room['price_amount'], $ci, $co);
                $expectNet = agent_net_price((float)$canon['total'], $agentRow, (int)$room['venue_id']);
                check('request: net = published (ONE path) × (1 − discount)',
                    eq((float)$res['quote']['net'], $expectNet) && eq((float)$res['quote']['published'], (float)$canon['total']));
                if (holds_agent_supported()) check('request: holds.agent_id links the agent', (int)$hold['agent_id'] === (int)$agentRow['id']);
                else echo "SKIP  holds.agent_id absent — run add_holds_agent.sql\n";
                if (holds_quoted_amount_supported()) check('request: net frozen on the hold',
                    eq((float)$hold['quoted_amount'], $expectNet) && $hold['quoted_currency'] === ($room['price_currency'] ?: 'USD'));
                else echo "SKIP  holds.quoted_amount absent — run add_holds_quoted_amount.sql\n";

                $sub = db_query('SELECT * FROM submissions WHERE id = :id', [':id' => $res['submission_id']])->fetch();
                $pl  = json_decode((string)$sub['payload_json'], true);
                check('request: submission payload names the agent, source and net',
                    (int)$pl['agent_id'] === (int)$agentRow['id'] && $pl['source'] === 'trade-portal'
                    && eq((float)$pl['quoted_total'], $expectNet) && $pl['traveller_email'] === 'zz-trav@example.com');
                check('request: submission contact is the agent, guest is the traveller, message carries the trade lines',
                    $sub['guest_email'] === 'zz-agent@example.com' && $sub['guest_name'] === 'ZZ Traveller'
                    && (int)$sub['room_id'] === (int)$room['id'] && (int)$sub['guests_children'] === 1
                    && str_contains((string)$sub['message'], 'ZZ Agency') && str_contains((string)$sub['message'], 'Late arrival'));

                // "Your requests"
                $list = agent_requests($agentRow);
                check('requests: the request lists with its hold status',
                    count($list) === 1 && (int)$list[0]['hold_id'] === (int)$res['hold_id'] && $list[0]['hold_status'] === 'pending');
                check('requests: reads as "On hold"', agent_request_status($list[0])['label'] === 'On hold');

                // Ledger: confirming snapshots source = 'agent' at the NET figure.
                if (bookings_supported() && holds_agent_supported() && holds_quoted_amount_supported()) {
                    db_query("UPDATE holds SET status = 'confirmed', confirmed_at = NOW() WHERE id = :id", [':id' => $res['hold_id']]);
                    bookings_sync_hold((int)$res['hold_id']);
                    $bk = db_query('SELECT * FROM bookings WHERE hold_id = :h', [':h' => $res['hold_id']])->fetch();
                    check('ledger: an agent hold books as source=agent, named by agency',
                        is_array($bk) && $bk['source'] === 'agent' && $bk['agent'] === 'ZZ Agency');
                    check('ledger: gross is the frozen net figure', is_array($bk) && eq((float)$bk['gross_amount'], $expectNet));
                } else {
                    echo "SKIP  ledger source check needs bookings + holds.agent_id + holds.quoted_amount\n";
                }

                // The race: the one unit is now held → a DIFFERENT agent asking for it is refused,
                // nothing written. (The same agent re-sending inside 30 s is a double submit — see below.)
                db_query("INSERT INTO travel_agents (name, agency, email, password_hash, discount_pct) VALUES ('ZZ Other','ZZ Other Co','zz-agent-2@example.com',:h,5)",
                    [':h' => password_hash('secret456', PASSWORD_DEFAULT)]);
                $otherAgent  = db_query("SELECT * FROM travel_agents WHERE email = 'zz-agent-2@example.com'")->fetch();
                $holdsBefore = (int) db_query('SELECT COUNT(*) FROM holds')->fetchColumn();
                $subsBefore  = (int) db_query('SELECT COUNT(*) FROM submissions')->fetchColumn();
                $again = agent_submit_request($otherAgent, $req, ['ip' => '127.0.0.1']);
                check('request: a second request for the one unit is refused (409)', $again['ok'] === false && $again['code'] === 409);
                check('request: … and writes nothing',
                    (int) db_query('SELECT COUNT(*) FROM holds')->fetchColumn() === $holdsBefore
                    && (int) db_query('SELECT COUNT(*) FROM submissions')->fetchColumn() === $subsBefore);

                // Validation: traveller name required; past dates refused.
                check('request: traveller name is required', agent_submit_request($agentRow, ['guest_name' => ''] + $req)['code'] === 422);
                check('request: a past check-in is refused', agent_submit_request($agentRow, ['check_in' => '2020-01-01', 'check_out' => '2020-01-03'] + $req)['code'] === 422);

                // A combination is an ENQUIRY: submission only, no hold, rooms in the payload.
                $combo = agent_submit_request($agentRow, [
                    'kind' => 'combo', 'venue_slug' => $venueSlug, 'rooms' => [['slug' => $room['slug'], 'units' => 1]],
                    'check_in' => $ci, 'check_out' => $co, 'adults' => 2, 'children' => 0, 'guest_name' => 'ZZ Group',
                ], ['ip' => '127.0.0.1']);
                check('request: a combination is recorded as an enquiry with no hold',
                    $combo['ok'] === true && $combo['mode'] === 'enquiry' && $combo['hold_id'] === null);
                $csub = db_query('SELECT * FROM submissions WHERE id = :id', [':id' => $combo['submission_id']])->fetch();
                $cpl  = json_decode((string)$csub['payload_json'], true);
                check('request: combo submission has no room_id and lists the rooms',
                    $csub['room_id'] === null && str_contains((string)($cpl['rooms'] ?? ''), (string)$room['name']));
                $list2 = agent_requests($agentRow);
                check('requests: both list, newest first, the combo reads "Enquiry sent"',
                    count($list2) === 2 && agent_request_status($list2[0])['label'] === 'Enquiry sent');
                check('request: a room from another property is refused in a combo',
                    agent_submit_request($agentRow, ['kind' => 'combo', 'venue_slug' => $venueSlug,
                        'rooms' => [['slug' => 'no-such-room-zz', 'units' => 1]], 'check_in' => $ci, 'check_out' => $co,
                        'adults' => 2, 'children' => 0, 'guest_name' => 'X'])['code'] === 422);

                // ── Review hardening ─────────────────────────────────────────
                // A double submit reuses the earlier request (nothing new written, nothing re-mailed).
                $subsNow = (int) db_query('SELECT COUNT(*) FROM submissions')->fetchColumn();
                $dup = agent_submit_request($agentRow, [
                    'kind' => 'combo', 'venue_slug' => $venueSlug, 'rooms' => [['slug' => $room['slug'], 'units' => 1]],
                    'check_in' => $ci, 'check_out' => $co, 'adults' => 2, 'children' => 0, 'guest_name' => 'ZZ Group',
                ], ['ip' => '127.0.0.1']);
                check('dedupe: an identical re-send returns the earlier request',
                    $dup['ok'] === true && !empty($dup['dedupe']) && (int)$dup['submission_id'] === (int)$combo['submission_id']
                    && (int) db_query('SELECT COUNT(*) FROM submissions')->fetchColumn() === $subsNow);

                // Isolation: a payload that merely CLAIMS an agent id (what any public form could post) is never listed.
                $listBefore = count(agent_requests($agentRow));
                db_query("INSERT INTO submissions (type, guest_name, guest_email, message, payload_json)
                          VALUES ('enquiry', 'Spoofer', 'spoof@example.com', 'x', :p)",
                    [':p' => json_encode(['agent_id' => (int)$agentRow['id'], 'source' => 'trade-portal', 'quoted_total' => 1])]);
                check('isolation: a spoofed payload agent_id does not appear in the agent’s requests',
                    count(agent_requests($agentRow)) === $listBefore);
                if (submissions_agent_supported()) {
                    check('request: submissions.agent_id is written by the server',
                        (int) db_query('SELECT agent_id FROM submissions WHERE id = :id', [':id' => $res['submission_id']])->fetchColumn() === (int)$agentRow['id']);
                } else {
                    echo "SKIP  submissions.agent_id absent — run add_holds_agent.sql\n";
                }
                check('request: the traveller’s phone is not stored as the booker’s contact',
                    (string) db_query('SELECT guest_phone FROM submissions WHERE id = :id', [':id' => $res['submission_id']])->fetchColumn() === '');

                // Throttle: counts only this agent's own requests; the pending-hold cap needs holds.agent_id.
                check('throttle: within limits → null', agent_request_throttled($agentRow, 100, 100) === null);
                check('throttle: the request window is enforced', agent_request_throttled($agentRow, 1, 100) !== null);

                // An enquiry-mode room creates the submission only (different dates, so the de-dupe stays out of the way).
                db_query("UPDATE rooms SET form_mode = 'enquiry' WHERE id = :id", [':id' => $room['id']]);
                $enq = agent_submit_request($agentRow, ['check_in' => '2098-07-01', 'check_out' => '2098-07-03'] + $req, ['ip' => '127.0.0.1']);
                check('request: an enquiry-mode room is recorded without a hold',
                    $enq['ok'] === true && $enq['mode'] === 'enquiry' && $enq['hold_id'] === null
                    && (int) db_query('SELECT room_id FROM submissions WHERE id = :id', [':id' => $enq['submission_id']])->fetchColumn() === (int)$room['id']);
                db_query("UPDATE rooms SET form_mode = 'availability' WHERE id = :id", [':id' => $room['id']]);
            }

            // ── Maya Ilai: the villa books through the locked allocator; per-bedroom products are refused ──
            $villa = db_query("SELECT r.* FROM rooms r JOIN venues v ON v.id = r.venue_id
                                WHERE r.slug = :s AND r.is_published = TRUE AND v.is_published = TRUE", [':s' => MAYA_ILAI_VILLA_ROOM_SLUG])->fetch();
            $villaUnits = $villa ? count(fetch_units_by_room((int)$villa['id'])) : 0;
            if (!$villa || $villaUnits === 0) {
                echo "SKIP  no published Maya Ilai villa with units — composite round-trip skipped\n";
            } else {
                db_query("UPDATE rooms SET form_mode = 'availability' WHERE id = :id", [':id' => $villa['id']]);
                $vres = agent_submit_request($agentRow, ['kind' => 'room', 'room_slug' => $villa['slug'],
                    'check_in' => '2098-08-10', 'check_out' => '2098-08-12', 'adults' => 6, 'children' => 0,
                    'guest_name' => 'ZZ Villa Party'], ['ip' => '127.0.0.1']);
                check('maya ilai: the villa is held through the locked allocator', $vres['ok'] === true && $vres['mode'] === 'hold' && (int)$vres['hold_id'] > 0);
                if ($vres['ok']) {
                    $vh = db_query("SELECT h.room_id, h.unit_id, u.room_id AS unit_room_id FROM holds h JOIN units u ON u.id = h.unit_id WHERE h.id = :id", [':id' => $vres['hold_id']])->fetch();
                    check('maya ilai: the hold names the villa product on a villa unit',
                        (int)$vh['room_id'] === (int)$villa['id'] && (int)$vh['unit_room_id'] === (int)$villa['id']);
                    if (components_supported()) {
                        // Through the locked allocator the villa product claims all four
                        // components explicitly (NULL = whole unit is the staff/OTA block rule).
                        $comps = mi_pg_array_decode(db_query('SELECT components FROM availability_blocks WHERE hold_id = :h', [':h' => $vres['hold_id']])->fetchColumn());
                        check('maya ilai: a villa hold claims every bedroom of its villa',
                            count($comps) === 4 && in_array('bunk', $comps, true) && in_array('living', $comps, true));
                    }
                }
                $bedroom = db_query("SELECT r.* FROM rooms r WHERE r.venue_id = :v AND r.is_published = TRUE AND r.slug <> :villa AND r.slug <> 'maya-ilai-studio'
                                      AND NOT EXISTS (SELECT 1 FROM units u WHERE u.room_id = r.id AND u.is_active) ORDER BY r.id LIMIT 1",
                    [':v' => $villa['venue_id'], ':villa' => $villa['slug']])->fetch();
                if ($bedroom) {
                    check('maya ilai: a per-bedroom product is not bookable through the portal', agent_room_bookable($bedroom) === false);
                    $bres = agent_submit_request($agentRow, ['kind' => 'room', 'room_slug' => $bedroom['slug'],
                        'check_in' => '2098-08-10', 'check_out' => '2098-08-12', 'adults' => 2, 'children' => 0,
                        'guest_name' => 'ZZ Bunk'], ['ip' => '127.0.0.1']);
                    check('maya ilai: … and the writer refuses it (422)', $bres['ok'] === false && $bres['code'] === 422);
                } else {
                    echo "SKIP  no unit-less Maya Ilai product to refuse\n";
                }
            }
        }
    } finally {
        db()->rollBack();
    }
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
