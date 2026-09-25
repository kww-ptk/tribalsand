<?php
declare(strict_types=1);
/**
 * AI gaps — classifier (pure, always runs) + a concierge_log round-trip inside a
 * rolled-back transaction when a DB with the table is reachable (else SKIP).
 *
 *   php tests/ai_gaps_logic.php
 */
require_once __DIR__ . '/../includes/ai-gaps.php';

$failures = 0;
function check(string $label, bool $cond): void {
    global $failures;
    echo ($cond ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if (!$cond) $failures++;
}
function turn(int $id, string $sid, string $q, string $a, int $tools = 1, bool $ok = true, string $at = '2026-09-20 10:00:00'): array {
    return ['id' => $id, 'session_id' => $sid, 'question' => $q, 'answer' => $a,
            'tools_used' => $tools ? 'check_availability' : '', 'tool_count' => $tools, 'ok' => $ok, 'created_at' => $at];
}

// ── Topics ───────────────────────────────────────────────────────────────────
check('airport → transfer',                ai_gap_topic('Can you arrange an airport pickup from Malindi?') === 'transfer');
check('transfer price → transfer, not price', ai_gap_topic('How much is the airport transfer?') === 'transfer');
check('dog → pets',                        ai_gap_topic('Can I bring my dog?') === 'pets');
check('carpet is not a pet',               ai_gap_topic('Is there carpet in the rooms?') === 'rooms');
check('accurate is not a rate',            ai_gap_topic('Is the map accurate?') === 'other');
check('prefix: activities',                ai_gap_topic('What activities are nearby?') === 'activities');
check('late checkout → checkin',           ai_gap_topic('Is late checkout possible?') === 'checkin');
check('vegan → food',                      ai_gap_topic('Do you cater for vegans?') === 'food');
check('how much → price',                  ai_gap_topic('How much for 3 nights at Zuri?') === 'price');
check('wifi → rooms',                      ai_gap_topic('Is the WiFi fast?') === 'rooms');
check('unknown → other',                   ai_gap_topic('Tell me a joke') === 'other');

// ── Answer confidence ────────────────────────────────────────────────────────
check('"I don\'t have" is unsure',         ai_gap_answer_unsure('I don\'t have details on transfers.'));
check('curly apostrophe handled',          ai_gap_answer_unsure('I’m not sure about pets, sorry.'));
check('"please contact" is unsure',        ai_gap_answer_unsure('Please contact reservations for that.'));
check('empty answer is unsure',            ai_gap_answer_unsure('  '));
check('confident answer is fine',          !ai_gap_answer_unsure('Zuri is free 3–6 Oct for 4 guests at $1,200 total.'));

// ── Reasons ──────────────────────────────────────────────────────────────────
check('ok=false → failed',                 ai_gap_reason(turn(1, 's', 'hi', '', 1, false)) === 'failed');
check('failed outranks unsure',            ai_gap_reason(turn(1, 's', 'hi', 'I don\'t have that', 1, false)) === 'failed');
check('unsure answer → unsure',            ai_gap_reason(turn(1, 's', 'Pets?', 'I\'m not sure, please contact us.')) === 'unsure');
check('priced Q with no lookup → no_lookup', ai_gap_reason(turn(1, 's', 'Price for 2 nights in Oct?', 'It is about $300.', 0)) === 'no_lookup');
check('clarifying question back is fine',  ai_gap_reason(turn(1, 's', 'Price for a 3-night stay in March?', 'Could you please provide the specific dates in March and the number of guests?', 0)) === null);
check('price detector: $300',              ai_gap_answer_has_price('It is about $300 a night.'));
check('price detector: KES 81,510',        ai_gap_answer_has_price('That comes to KES 81,510.'));
check('price detector: 1,200 USD',         ai_gap_answer_has_price('Roughly 1,200 USD in total.'));
check('price detector: no figure',         !ai_gap_answer_has_price('Which dates in March, and for how many guests?'));
check('price detector: bare number no',    !ai_gap_answer_has_price('The villa sleeps 8 and has 4 bedrooms.'));
check('chit-chat with no lookup is fine',  ai_gap_reason(turn(1, 's', 'Hello there', 'Hi! How can I help?', 0)) === null);
check('good looked-up answer is fine',     ai_gap_reason(turn(1, 's', 'Free 3-6 Oct?', 'Yes, Zuri is free at $1,200.')) === null);

// ── Repeats + classify ───────────────────────────────────────────────────────
check('same question, different case',     ai_gap_same_question('Can I bring my dog?', 'can i bring my dog'));
check('near-identical counts',             ai_gap_same_question('Can I bring my dog?', 'Can I bring my dogs?'));
check('different questions do not',        !ai_gap_same_question('Can I bring my dog?', 'What time is breakfast?'));

$rows = [
    turn(1, 'A', 'Can I bring my dog?',      'Dogs are welcome at some properties.', 0, true, '2026-09-20 10:00:00'),
    turn(2, 'A', 'Can I bring my dog??',     'Yes at Maya Kobe.',                     0, true, '2026-09-20 10:01:00'),
    turn(3, 'B', 'Can I bring my dog?',      'Yes at Maya Kobe.',                     0, true, '2026-09-20 10:02:00'),  // other session — not a repeat
    turn(4, 'C', 'Airport transfer price?',  'I don\'t have transfer prices.',        0, true, '2026-09-21 09:00:00'),
    turn(5, 'C', 'Is Zuri free 3-6 Oct?',    'Yes — $1,200 total.',                   1, true, '2026-09-21 09:05:00'),
    turn(6, 'D', 'hello',                    '',                                      0, false, '2026-09-22 08:00:00'),
];
$gaps = ai_gap_classify($rows);
$ids  = array_column($gaps, 'id');
check('flags only the gaps',               $ids === [6, 4, 1]);
check('newest first',                      ($gaps[0]['id'] ?? 0) === 6);
check('earlier of a repeated pair flagged', ($gaps[2]['reason'] ?? '') === 'repeat' && ($gaps[2]['id'] ?? 0) === 1);
check('repeat is per session',             !in_array(3, $ids, true));
check('topic attached',                    ($gaps[1]['topic'] ?? '') === 'transfer' && ($gaps[1]['reason'] ?? '') === 'unsure');
$counts = ai_gap_topic_counts($gaps);
check('topic counts',                      ($counts['transfer'] ?? 0) === 1 && ($counts['pets'] ?? 0) === 1 && ($counts['other'] ?? 0) === 1);
check('empty input → no gaps',             ai_gap_classify([]) === []);

// ── DB round-trip (rolled back) ──────────────────────────────────────────────
$dbOk = false;
try { $dbOk = concierge_log_supported(); } catch (Throwable $e) { $dbOk = false; }
if (!$dbOk) {
    echo "SKIP  DB round-trip (no database or concierge_log table)\n";
} else {
    db()->beginTransaction();
    try {
        db_query("INSERT INTO concierge_log (client_ip, session_id, question, answer, tools_used, tool_count, ok)
                  VALUES ('203.0.113.9', 'test-gaps', 'ZZTEST can I bring my dog?', 'I don''t have that information.', '', 0, TRUE)");
        $f = ai_gaps_fetch(7);
        $mine = array_values(array_filter($f['rows'], fn($r) => $r['session_id'] === 'test-gaps'));
        check('fetch returns the logged turn',  count($mine) === 1);
        check('fetch never selects client_ip',  !array_key_exists('client_ip', $mine[0] ?? []));
        check('ok normalised to bool',          ($mine[0]['ok'] ?? null) === true);
        $g = ai_gap_classify($mine);
        check('logged turn classified as gap',  ($g[0]['reason'] ?? '') === 'unsure' && ($g[0]['topic'] ?? '') === 'pets');
    } finally {
        db()->rollBack();
    }
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
