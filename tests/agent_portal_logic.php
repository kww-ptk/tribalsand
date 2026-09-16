<?php
declare(strict_types=1);
// Travel-agent portal — discount resolution + net pricing (pure), plus a DB
// round-trip (agent row incl. JSONB venue overrides, password hash) inside ONE
// transaction that is rolled back. Run: php tests/agent_portal_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/agent.php';
require_once __DIR__ . '/../includes/bookings.php';   // bookings_sync_hold() for the ledger check
require_once __DIR__ . '/../includes/mail.php';       // _hold_notification_html() for the trade-row check
require_once __DIR__ . '/../includes/submission-notes.php'; // thread + unread helpers (Item 2/4)

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
check('status: no hold = sent, awaiting reservations', agent_request_status(['hold_status' => null], $now)['label'] === 'Sent');
$st = agent_request_status(['hold_status' => 'pending', 'expires_at' => '2026-09-15 21:30:00'], $now);
check('status: pending shows the countdown', $st['label'] === 'On hold' && $st['note'] === 'Expires in 11h 30m');
check('status: pending with no expiry awaits confirmation', agent_request_status(['hold_status' => 'pending', 'expires_at' => null], $now)['note'] === 'Awaiting confirmation');
check('status: confirmed / expired / cancelled labels',
    agent_request_status(['hold_status' => 'confirmed'], $now)['label'] === 'Confirmed'
    && agent_request_status(['hold_status' => 'expired'], $now)['label'] === 'Expired'
    && agent_request_status(['hold_status' => 'cancelled'], $now)['class'] === 'cancelled');

// ── Status reflects the admin pipeline BEFORE a hold exists (pure, Item 2) ────
check('status: no hold + no sub-status still reads Sent',
    agent_request_status(['hold_status' => null], $now)['label'] === 'Sent');
check('status: received sub-status reads Sent',
    agent_request_status(['hold_status' => null, 'sub_status' => 'received'], $now)['label'] === 'Sent');
check('status: option_sent reads "In review"',
    agent_request_status(['hold_status' => null, 'sub_status' => 'option_sent'], $now)['label'] === 'In review');
check('status: to_follow_up reads "In review"',
    agent_request_status(['hold_status' => null, 'sub_status' => 'to_follow_up'], $now)['label'] === 'In review');
check('status: dates_unavailable is surfaced',
    agent_request_status(['hold_status' => null, 'sub_status' => 'dates_unavailable'], $now)['label'] === 'Dates unavailable');
check('status: not_interested reads "Closed"',
    agent_request_status(['hold_status' => null, 'sub_status' => 'not_interested'], $now)['label'] === 'Closed');
check('status: booked (no hold yet) reads "Booked"',
    agent_request_status(['hold_status' => null, 'sub_status' => 'booked'], $now)['label'] === 'Booked');
check('status: a hold ALWAYS wins over the sub-status',
    agent_request_status(['hold_status' => 'confirmed', 'sub_status' => 'option_sent'], $now)['label'] === 'Confirmed');
check('status: an unknown sub-status falls back to Sent',
    agent_request_status(['hold_status' => null, 'sub_status' => 'whatever'], $now)['label'] === 'Sent');
check('sub-status mapper: unknown slug returns null',
    agent_request_sub_status('nope') === null && agent_request_sub_status(null) === null);

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

            }

            // ── Request writer round-trip (all rolled back) ──────────────────
            // A trade request NEVER places a hold (the owner's rule): one submission
            // with a server-written agent id and the net quote in its payload. The
            // hold is placed by reservations (Convert to Hold) and tagged from it.
            $room = db_query(
                "SELECT r.* FROM rooms r JOIN venues v ON v.id = r.venue_id
                  WHERE r.is_published = TRUE AND v.is_published = TRUE AND r.is_entire_place = FALSE
                    AND r.price_amount > 0 AND r.slug NOT LIKE 'maya-ilai-%'
                    AND EXISTS (SELECT 1 FROM units u WHERE u.room_id = r.id AND u.is_active)
                  ORDER BY r.id LIMIT 1")->fetch();
            if (!$room) {
                echo "SKIP  no priced published room with a unit to request\n";
            } else {
                $venueSlug = (string) db_query('SELECT slug FROM venues WHERE id = :id', [':id' => $room['venue_id']])->fetchColumn();
                $ci = '2098-06-10'; $co = '2098-06-12';
                $req = ['kind' => 'room', 'room_slug' => $room['slug'], 'check_in' => $ci, 'check_out' => $co,
                        'adults' => 2, 'children' => 1, 'guest_name' => 'ZZ Traveller',
                        'guest_email' => 'zz-trav@example.com', 'guest_phone' => '+254700000000', 'notes' => 'Late arrival'];
                $holdsBefore = (int) db_query('SELECT COUNT(*) FROM holds')->fetchColumn();
                $res = agent_submit_request($agentRow, $req, ['source_page' => 'test', 'ip' => '127.0.0.1']);
                check('request: saves as a request, never a hold', $res['ok'] === true && $res['mode'] === 'enquiry' && empty($res['hold_id']));
                check('request: no hold or block is written',
                    (int) db_query('SELECT COUNT(*) FROM holds')->fetchColumn() === $holdsBefore
                    && !db_query('SELECT 1 FROM holds WHERE submission_id = :s', [':s' => $res['submission_id']])->fetchColumn());

                $canon     = room_stay_quote((int)$room['id'], (float)$room['price_amount'], $ci, $co);
                $expectNet = agent_net_price((float)$canon['total'], $agentRow, (int)$room['venue_id']);
                check('request: net = published (ONE path) × (1 − discount)',
                    eq((float)$res['quote']['net'], $expectNet) && eq((float)$res['quote']['published'], (float)$canon['total']));

                $sub = db_query('SELECT * FROM submissions WHERE id = :id', [':id' => $res['submission_id']])->fetch();
                $pl  = json_decode((string)$sub['payload_json'], true);
                check('request: payload names the agent, source, signed marker and net',
                    (int)$pl['agent_id'] === (int)$agentRow['id'] && $pl['source'] === 'trade-portal'
                    && hash_equals(agent_request_sig((int)$agentRow['id']), (string)($pl['agent_sig'] ?? ''))
                    && eq((float)$pl['quoted_total'], $expectNet) && $pl['traveller_email'] === 'zz-trav@example.com');
                if (submissions_agent_supported()) check('request: submissions.agent_id is written by the server', (int)$sub['agent_id'] === (int)$agentRow['id']);
                else echo "SKIP  submissions.agent_id absent — run add_holds_agent.sql\n";
                check('request: contact is the agent, guest is the traveller, phone only in the payload',
                    $sub['guest_email'] === 'zz-agent@example.com' && $sub['guest_name'] === 'ZZ Traveller'
                    && (string)$sub['guest_phone'] === '' && ($pl['traveller_phone'] ?? '') === '+254700000000'
                    && (int)$sub['room_id'] === (int)$room['id'] && (int)$sub['guests_children'] === 1
                    && str_contains((string)$sub['message'], 'ZZ Agency') && str_contains((string)$sub['message'], 'Late arrival'));
                check('request: the message tells reservations no hold was placed', str_contains((string)$sub['message'], 'no hold placed'));

                // "Your requests": sent, awaiting reservations.
                $list = agent_requests($agentRow);
                check('requests: lists the request as sent, with no hold yet',
                    count($list) === 1 && (int)$list[0]['id'] === (int)$res['submission_id'] && $list[0]['hold_id'] === null
                    && agent_request_status($list[0])['label'] === 'Sent');

                // ── Per-request view + thread + ownership (Item 2) ───────────────
                $one = agent_fetch_request($agentRow, (int)$res['submission_id']);
                check('request-view: the owning agent can fetch their own request',
                    is_array($one) && (int)$one['id'] === (int)$res['submission_id'] && isset($one['payload']));
                check('request-view: a wrong agent id cannot fetch this request',
                    agent_fetch_request(['id' => (int)$agentRow['id'] + 99999], (int)$res['submission_id']) === null);
                check('request-view: message ownership is re-checked (foreign id → 404)',
                    agent_post_message(['id' => (int)$agentRow['id'] + 99999], (int)$res['submission_id'], 'hi')['code'] === 404);
                check('request-view: an empty message is refused',
                    agent_post_message($agentRow, (int)$res['submission_id'], '   ')['ok'] === false);
                if (submission_notes_kind_supported()) {
                    $before = count(fetch_agent_visible_thread((int)$res['submission_id']));
                    $sent = agent_post_message($agentRow, (int)$res['submission_id'], 'Traveller prefers a sea view.');
                    check('request-view: the agent can post a message', $sent['ok'] === true && $sent['note_id'] > 0);
                    $thread = fetch_agent_visible_thread((int)$res['submission_id']);
                    check('request-view: the agent’s message appears as a guest_reply in the visible thread',
                        count($thread) === $before + 1 && $thread[count($thread) - 1]['kind'] === 'guest_reply'
                        && str_contains((string)$thread[count($thread) - 1]['body'], 'sea view'));
                    // An internal staff note must NEVER be visible to the agent.
                    add_submission_note((int)$res['submission_id'], null, 'INTERNAL: check rack rate', 'note', 'Staff');
                    $thread2 = fetch_agent_visible_thread((int)$res['submission_id']);
                    check('request-view: an internal note is hidden from the agent',
                        count($thread2) === count($thread)
                        && !array_filter($thread2, fn($n) => str_contains((string)$n['body'], 'INTERNAL')));
                    // A staff reply IS visible.
                    add_submission_note((int)$res['submission_id'], null, 'Sea view confirmed.', 'reply', 'Reservations');
                    $thread3 = fetch_agent_visible_thread((int)$res['submission_id']);
                    check('request-view: a staff reply is visible to the agent',
                        (bool) array_filter($thread3, fn($n) => $n['kind'] === 'reply' && str_contains((string)$n['body'], 'Sea view confirmed')));
                    if (submission_reply_flags_supported()) {
                        check('request-view: an agent message raises the unread flag',
                            !empty(submission_unread_reply_ids([(int)$res['submission_id']])[(int)$res['submission_id']]));
                    }
                } else {
                    echo "SKIP  submission_notes kind column absent — thread checks skipped\n";
                }

                // Reservations convert it: the hold is tagged with the agent + the net for the booked room.
                $unit   = db_query('SELECT id FROM units WHERE room_id = :r AND is_active = TRUE ORDER BY sort_order LIMIT 1', [':r' => $room['id']])->fetch();
                $holdId = create_hold_with_block((int)$unit['id'], (int)$res['submission_id'], $ci, $co, 'ZZ Traveller', 'zz-agent@example.com', 'pending', 24, null, (int)$room['id']);
                $tag    = agent_tag_converted_hold($holdId, $sub);
                check('convert: a converted trade request tags the hold', is_array($tag) && $tag['agency'] === 'ZZ Agency' && eq((float)$tag['net'], $expectNet));
                $hold = db_query('SELECT * FROM holds WHERE id = :id', [':id' => $holdId])->fetch();
                if (holds_agent_supported()) check('convert: holds.agent_id links the agent', (int)$hold['agent_id'] === (int)$agentRow['id']);
                else echo "SKIP  holds.agent_id absent — run add_holds_agent.sql\n";
                if (holds_quoted_amount_supported()) check('convert: the net for the booked room is frozen on the hold',
                    eq((float)$hold['quoted_amount'], $expectNet) && $hold['quoted_currency'] === ($room['price_currency'] ?: 'USD'));
                else echo "SKIP  holds.quoted_amount absent — run add_holds_quoted_amount.sql\n";
                check('convert: a guest enquiry is never tagged', agent_tag_converted_hold($holdId, ['payload_json' => '{"agent_id": 1}']) === null);
                check('requests: the converted request now reads "On hold"', agent_request_status(agent_requests($agentRow)[0])['label'] === 'On hold');

                // Ledger: confirming snapshots source = 'agent' at the NET figure.
                if (bookings_supported() && holds_agent_supported() && holds_quoted_amount_supported()) {
                    db_query("UPDATE holds SET status = 'confirmed', confirmed_at = NOW() WHERE id = :id", [':id' => $holdId]);
                    bookings_sync_hold($holdId);
                    $bk = db_query('SELECT * FROM bookings WHERE hold_id = :h', [':h' => $holdId])->fetch();
                    check('ledger: a converted trade request books as source=agent, named by agency',
                        is_array($bk) && $bk['source'] === 'agent' && $bk['agent'] === 'ZZ Agency');
                    check('ledger: gross is the frozen net figure', is_array($bk) && eq((float)$bk['gross_amount'], $expectNet));
                } else {
                    echo "SKIP  ledger source check needs bookings + holds.agent_id + holds.quoted_amount\n";
                }

                // Validation.
                check('request: traveller name is required', agent_submit_request($agentRow, ['guest_name' => ''] + $req)['code'] === 422);
                check('request: a past check-in is refused', agent_submit_request($agentRow, ['check_in' => '2020-01-01', 'check_out' => '2020-01-03'] + $req)['code'] === 422);

                // A double submit reuses the earlier request (nothing new written, nothing re-mailed).
                $subsNow = (int) db_query('SELECT COUNT(*) FROM submissions')->fetchColumn();
                $dup = agent_submit_request($agentRow, $req, ['ip' => '127.0.0.1']);
                check('dedupe: an identical re-send returns the earlier request',
                    $dup['ok'] === true && !empty($dup['dedupe']) && (int)$dup['submission_id'] === (int)$res['submission_id']
                    && (int) db_query('SELECT COUNT(*) FROM submissions')->fetchColumn() === $subsNow);

                // A combination is a request too: no hold, rooms listed in the payload.
                $combo = agent_submit_request($agentRow, [
                    'kind' => 'combo', 'venue_slug' => $venueSlug, 'rooms' => [['slug' => $room['slug'], 'units' => 1]],
                    'check_in' => $ci, 'check_out' => $co, 'adults' => 2, 'children' => 0, 'guest_name' => 'ZZ Group',
                ], ['ip' => '127.0.0.1']);
                check('request: a combination is recorded with no hold and its rooms listed',
                    $combo['ok'] === true && empty($combo['dedupe'])
                    && db_query('SELECT room_id, payload_json FROM submissions WHERE id = :id', [':id' => $combo['submission_id']])->fetch()['room_id'] === null
                    && str_contains((string)(json_decode((string)db_query('SELECT payload_json FROM submissions WHERE id = :id', [':id' => $combo['submission_id']])->fetchColumn(), true)['rooms'] ?? ''), (string)$room['name']));
                check('request: a room from another property is refused in a combo',
                    agent_submit_request($agentRow, ['kind' => 'combo', 'venue_slug' => $venueSlug,
                        'rooms' => [['slug' => 'no-such-room-zz', 'units' => 1]], 'check_in' => $ci, 'check_out' => $co,
                        'adults' => 2, 'children' => 0, 'guest_name' => 'X'])['code'] === 422);

                // Isolation: a payload that merely CLAIMS an agent id (what any public form could post) is never listed.
                $listBefore = count(agent_requests($agentRow));
                db_query("INSERT INTO submissions (type, guest_name, guest_email, message, payload_json)
                          VALUES ('enquiry', 'Spoofer', 'spoof@example.com', 'x', :p)",
                    [':p' => json_encode(['agent_id' => (int)$agentRow['id'], 'source' => 'trade-portal', 'quoted_total' => 1])]);
                check('isolation: a spoofed payload agent_id does not appear in the agent’s requests',
                    count(agent_requests($agentRow)) === $listBefore);

                // Throttle counts only this agent's own requests.
                check('throttle: within limits → null', agent_request_throttled($agentRow, 100) === null);
                check('throttle: the request window is enforced', agent_request_throttled($agentRow, 1) !== null);
            }

            // ── Maya Ilai: the villa can be requested; per-bedroom products are refused ──
            $villa = db_query("SELECT r.* FROM rooms r JOIN venues v ON v.id = r.venue_id
                                WHERE r.slug = :s AND r.is_published = TRUE AND v.is_published = TRUE", [':s' => MAYA_ILAI_VILLA_ROOM_SLUG])->fetch();
            if (!$villa) {
                echo "SKIP  no published Maya Ilai villa — composite checks skipped\n";
            } else {
                $vres = agent_submit_request($agentRow, ['kind' => 'room', 'room_slug' => $villa['slug'],
                    'check_in' => '2098-08-10', 'check_out' => '2098-08-12', 'adults' => 6, 'children' => 0,
                    'guest_name' => 'ZZ Villa Party'], ['ip' => '127.0.0.1']);
                check('maya ilai: the villa can be requested (no hold placed)',
                    $vres['ok'] === true && !db_query('SELECT 1 FROM holds WHERE submission_id = :s', [':s' => $vres['submission_id']])->fetchColumn());
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
