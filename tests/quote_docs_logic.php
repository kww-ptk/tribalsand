<?php
declare(strict_types=1);
// Quote documents (Part C) — quote refs, the request block, the printable
// document model, terms; saving quotes on an enquiry in ONE rolled-back
// transaction. Run: php tests/quote_docs_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/quote-docs.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Quote reference ──────────────────────────────────────────────────────────
$r = qb_quote_ref(12, 2, '2026-09-29 14:05:00');
check('ref: enquiry + option', $r === make_submission_ref(12) . ' · Option 2' && (bool)preg_match('/^TSR-12-[0-9a-f]{6} · Option 2$/', $r));
check('ref: outside an enquiry = Q-YYYYMMDD-HHMM', qb_quote_ref(null, null, '2026-09-29 14:05:00') === 'Q-20260929-1405');
check('ref: enquiry without an option = the enquiry ref', qb_quote_ref(12, null, '2026-09-29 14:05:00') === make_submission_ref(12));
check('ref: zero ids read as none', qb_quote_ref(0, 0, '2026-09-29 09:00:00') === 'Q-20260929-0900');

// ── Request block (what the guest asked for — never the free-text message) ───
$sub = ['check_in' => '2027-03-24', 'check_out' => '2027-03-28', 'guests_adults' => 2, 'guests_children' => 1,
        'room_name' => 'Maji Suite', 'venue_name' => 'Zuri', 'tour_name' => 'Sunset dhow',
        'message' => 'SECRET my card is 4111', 'guest_name' => 'Sofia'];
$rb = qb_request_block($sub);
$flat = implode(' | ', array_map(fn($x) => $x['label'] . ': ' . $x['value'], $rb));
check('request: dates + nights', str_contains($flat, 'Dates: 24 Mar 2027 → 28 Mar 2027 · 4 nights'));
check('request: party', str_contains($flat, 'Guests: 2 adults, 1 child'));
check('request: room with its property', str_contains($flat, 'Room: Zuri — Maji Suite'));
check('request: activity', str_contains($flat, 'Activity: Sunset dhow'));
check('request: never the message', !str_contains($flat, 'SECRET') && !str_contains($flat, '4111'));
$rb2 = qb_request_block(['check_in' => '2027-03-24', 'check_out' => null, 'guests_adults' => 0, 'guests_children' => 0,
                         'room_name' => null, 'venue_name' => 'Maya Kobe', 'tour_name' => null]);
$flat2 = implode(' | ', array_map(fn($x) => $x['label'] . ': ' . $x['value'], $rb2));
check('request: only a check-in', str_contains($flat2, 'Dates: from 24 Mar 2027'));
check('request: property without a room', str_contains($flat2, 'Property: Maya Kobe'));
check('request: no party row when unknown', !str_contains($flat2, 'Guests'));
check('request: empty enquiry → empty block', qb_request_block(['message' => 'hi']) === []);
check('request: garbage dates are dropped', qb_request_block(['check_in' => 'nope', 'check_out' => '2027-13-45']) === []);

// ── Document model (pure; escaping is the template's job) ────────────────────
$priced = [
    'currency' => 'KES', 'check_in' => '2027-03-24', 'check_out' => '2027-03-28', 'nights' => 4,
    'name' => 'Sofia Martin', 'adults' => 2, 'children' => 1, 'issued' => '2026-09-29',
    'quote_rooms' => [
        ['venue' => 'Zuri', 'room' => 'Maji Suite', 'name' => 'Zuri — Maji Suite', 'qty' => 1, 'mix' => '2 Mid + 2 Peak', 'amt' => 229680.0],
        ['venue' => 'Maya Kobe', 'room' => 'Haze Suite', 'name' => 'Maya Kobe — Haze Suite', 'qty' => 2, 'mix' => '4 Standard', 'amt' => 100000.0],
    ],
    'quote_extras' => [['label' => 'Airport → Property × 1', 'amt' => 6450.0]],
    'summary' => ['accommodation' => 329680.0, 'discount' => 32968.0, 'discount_pct' => 10.0, 'extras' => 6450.0, 'total' => 303162.0],
    'lines' => [
        ['kind' => 'room', 'label' => 'Zuri — Maji Suite × 1', 'amt' => 229680.0],
        ['kind' => 'room', 'label' => 'Maya Kobe — Haze Suite × 2', 'amt' => 100000.0],
        ['kind' => 'discount', 'label' => 'Discount 10% (Returning guest)', 'amt' => 32968.0],
        ['kind' => 'extra', 'label' => 'Airport → Property × 1', 'amt' => 6450.0],
        ['kind' => 'total', 'label' => 'Total', 'amt' => 303162.0],
    ],
    'fx_note' => 'Converted at 1 USD = 129 KES on 29 Sep 2026.',
];
$meta = ['ref' => 'TSR-12-abcdef · Option 2', 'issued' => '2026-09-29',
         'request' => [['label' => 'Dates', 'value' => '24 Mar 2027 → 28 Mar 2027 · 4 nights']]];
$terms = "Line one <b>bold</b>\nLine two";
$doc = qb_quote_document($priced, $meta, $terms);
check('doc: title + ref + issued', $doc['title'] === 'Quotation' && $doc['ref'] === 'TSR-12-abcdef · Option 2' && $doc['issued'] === '29 Sep 2026');
check('doc: prepared for', $doc['prepared_for']['name'] === 'Sofia Martin'
    && $doc['prepared_for']['stay'] === '24 Mar 2027 → 28 Mar 2027'
    && $doc['prepared_for']['nights'] === '4 nights' && $doc['prepared_for']['party'] === '2 adults, 1 child');
check('doc: request rows carried', $doc['request'] === $meta['request']);
check('doc: accommodation rows', count($doc['rooms']) === 2 && $doc['rooms'][0]['property'] === 'Zuri'
    && $doc['rooms'][0]['room'] === 'Maji Suite' && $doc['rooms'][1]['qty'] === 2
    && $doc['rooms'][0]['mix'] === '2 Mid season nights + 2 Peak season nights' && $doc['rooms'][1]['mix'] === '4 Standard season nights' && $doc['rooms'][0]['amount'] === 'KES 229,680');
check('doc: accommodation subtotal', $doc['accommodation'] === 'KES 329,680');
check('doc: discount', $doc['discount'] === ['label' => 'Discount 10% (Returning guest)', 'amount' => '−KES 32,968']);
check('doc: extras', $doc['extras'] === [['label' => 'Airport → Property × 1', 'amount' => 'KES 6,450']]);
check('doc: total', $doc['total'] === 'KES 303,162' && $doc['currency'] === 'KES');
check('doc: fx note', $doc['fx_note'] === 'Converted at 1 USD = 129 KES on 29 Sep 2026.');
check('doc: terms are raw (the template escapes)', $doc['terms'] === $terms);
check('doc: contact', $doc['contact']['email'] === 'reservations@tribalsand.com' && $doc['contact']['phone'] === '+254 115 115 247'
    && $doc['contact']['web'] === 'tribalsand.com');
$docNo = qb_quote_document(['summary' => ['discount' => 0, 'total' => 50.0], 'quote_rooms' => [], 'quote_extras' => [['label' => 'Guide × 1', 'amt' => 50.0]],
                            'currency' => 'USD', 'nights' => 0, 'adults' => 2, 'children' => 0, 'name' => '', 'fx_note' => null], ['ref' => 'Q-20260929-1405'], 'T');
check('doc: no rooms / no discount / no fx', $docNo['rooms'] === [] && $docNo['discount'] === null && $docNo['accommodation'] === null
    && $docNo['fx_note'] === null && $docNo['total'] === '$50' && $docNo['prepared_for']['stay'] === '' && $docNo['request'] === []);
check('doc: issued falls back to the priced date', qb_quote_document(['issued' => '2026-09-01', 'summary' => ['total' => 1.0]], [], 'T')['issued'] === '1 Sep 2026');
check('terms: default text', QB_QUOTE_TERMS_DEFAULT === 'This quote is not a reservation. Prices are valid on the date issued and subject to availability until booked.');
check('text: saved quote leads with the ref', qb_quote_text_with_ref("Tribal Sand — quote\nTotal: $5", 'TSR-1-abcdef · Option 1')
    === "TSR-1-abcdef · Option 1\nTribal Sand — quote\nTotal: $5");

// ── DB: save on an enquiry (ONE rolled-back transaction) ─────────────────────
$pdo = null;
try { $pdo = db(); } catch (Throwable $e) { echo "SKIP  DB block (no database)\n"; }
if ($pdo) {
    $pdo->beginTransaction();
    try {
        // Postgres DDL is transactional — apply what the local DB lacks, rolled back below.
        $dir = __DIR__ . '/../db/migrations/';
        if (!db_query("SELECT to_regclass('submission_notes')")->fetchColumn()) {
            $pdo->exec(file_get_contents($dir . 'add_submission_notes.sql'));
            $pdo->exec(file_get_contents($dir . 'add_submission_notes_kind.sql'));
        }
        if (!db_query("SELECT to_regclass('submission_quotes')")->fetchColumn()) {
            $pdo->exec(file_get_contents($dir . 'add_submission_quotes.sql'));
        }
        check('supported: table present', qb_quotes_supported(true));

        // Terms reader: default when unset/blank, the owner's text when set.
        db_query("DELETE FROM settings WHERE setting_key = 'quote_terms'");
        check('terms: default when unset', qb_quote_terms() === QB_QUOTE_TERMS_DEFAULT);
        set_setting('quote_terms', "  ");
        check('terms: default when blank', qb_quote_terms() === QB_QUOTE_TERMS_DEFAULT);
        set_setting('quote_terms', "Deposit 30%.\nBalance 30 days before arrival.");
        check('terms: owner text', qb_quote_terms() === "Deposit 30%.\nBalance 30 days before arrival.");

        $cat = qb_catalog(null);
        $room = null;
        foreach ($cat['rooms'] as $cr) if ((float)$cr['price_amount'] > 0) { $room = $cr; break; }
        if (!$room) { echo "SKIP  save (no priced published room)\n"; }
        else {
            $rid = (int)$room['id']; $vid = (int)$room['venue_id'];
            $admin = (int)db_query('SELECT id FROM admin_users ORDER BY id LIMIT 1')->fetchColumn() ?: null;
            $sid = (int)db_query(
                "INSERT INTO submissions (type, guest_name, guest_email, message, check_in, check_out, guests_adults, guests_children, room_id, payload_json)
                 VALUES ('enquiry', 'Test Guest', 'test@example.com', 'private message', '2099-05-10', '2099-05-13', 2, 0, :r, '{}') RETURNING id",
                [':r' => $rid])->fetchColumn();
            $sel = ['name' => 'Test Guest', 'check_in' => '2099-05-10', 'check_out' => '2099-05-13', 'adults' => 2, 'children' => 0,
                    'cur' => 'KES', 'rooms' => [['id' => $rid, 'qty' => 1, 'guests' => 2]], 'want_free' => true,
                    'extras' => [['key' => 'x1', 'kind' => 'custom', 'label' => 'Guide', 'qty' => 1, 'price' => 50, 'price_cur' => 'USD', 'basis' => 'stay']]];

            $s1 = qb_quote_save($sid, $admin, $sel, null);
            $s2 = qb_quote_save($sid, $admin, ['cur' => 'USD'] + $sel, [$vid]);
            $live = qb_price_selection($sel, null);
            check('save: first is option 1', $s1['option_no'] === 1);
            check('save: second is option 2', $s2['option_no'] === 2);
            check('save: ref = enquiry ref · option', $s2['ref'] === make_submission_ref($sid) . ' · Option 2');
            check('save: text leads with the ref', str_starts_with($s1['text'], $s1['ref'] . "\nTribal Sand — quote for Test Guest"));
            check('save: total == qb_price_selection total', abs($s1['total'] - $live['summary']['total']) < 0.005 && $s1['currency'] === 'KES');

            $row = qb_quote_fetch($s1['id']);
            $snap = $row['snapshot'];
            check('fetch: row + decoded snapshot', $row && (int)$row['submission_id'] === $sid && (int)$row['option_no'] === 1 && is_array($snap));
            check('snapshot: stored total == priced total', abs((float)$row['total'] - $live['summary']['total']) < 0.005
                && abs((float)$snap['quote']['summary']['total'] - $live['summary']['total']) < 0.005);
            check('snapshot: meta', $snap['meta']['ref'] === $s1['ref'] && $snap['meta']['currency'] === 'KES'
                && $snap['meta']['adults'] === 2 && $snap['meta']['name'] === 'Test Guest'
                && $snap['meta']['issued'] === date('Y-m-d') && is_numeric($snap['meta']['fx_rate'])
                && $snap['meta']['terms'] === "Deposit 30%.\nBalance 30 days before arrival.");
            check('snapshot: request block (no message)', is_array($snap['meta']['request']) && $snap['meta']['request']
                && !str_contains(json_encode($snap), 'private message'));
            check('snapshot: selection kept, want_free dropped', $snap['sel']['rooms'][0]['id'] === $rid && !array_key_exists('want_free', $snap['sel']));

            check('snapshot: no catalogue rooms / free counts stored', !array_key_exists('rooms', $snap['quote']) && !array_key_exists('extras', $snap['quote'])
                && !array_key_exists('notices', $snap['quote']));
            check('snapshot: keeps what the document needs', isset($snap['quote']['quote_rooms'], $snap['quote']['quote_extras'], $snap['quote']['lines'],
                $snap['quote']['summary'], $snap['quote']['currency'], $snap['quote']['nights'], $snap['quote']['check_in']) && array_key_exists('fx_note', $snap['quote']));
            $re = qb_quote_document((array)$snap['quote'], (array)$snap['meta'], (string)$snap['meta']['terms']);
            $liveDoc = qb_quote_document($live, ['ref' => $s1['ref'], 'issued' => date('Y-m-d')], (string)$snap['meta']['terms']);
            check('reprint: renders from the snapshot alone', $re['total'] === $liveDoc['total'] && $re['rooms'] === $liveDoc['rooms']
                && $re['extras'] === $liveDoc['extras'] && $re['prepared_for'] === $liveDoc['prepared_for'] && $re['rooms'] && $re['ref'] === $s1['ref']);
            check('reprint: no room line says "Base"', !str_contains(json_encode($re['rooms']), 'Base'));
            check('snapshot: copy text kept for a re-copy', str_contains((string)$snap['quote']['text'], 'Tribal Sand — quote for Test Guest'));

            $list = qb_quotes_for_submission($sid);
            check('list: both options, in order', array_column($list, 'option_no') === [1, 2]);
            check('list: currency + total per option', $list[1]['currency'] === 'USD' && (float)$list[1]['total'] > 0);

            if (submission_notes_supported()) {
                $notes = db_query("SELECT body FROM submission_notes WHERE submission_id = :s ORDER BY id", [':s' => $sid])->fetchAll(PDO::FETCH_COLUMN);
                check('note: added per save', count($notes) === 2
                    && $notes[0] === 'Quote Option 1 saved — ' . rc_money_text($live['summary']['total'], 'KES'));
            } else { echo "SKIP  note (no submission_notes)\n"; }

            $refused = function (callable $fn): bool { try { $fn(); return false; } catch (QbQuoteRefusal $e) { return true; } };
            check('refuse: out of scope (empty scope)', $refused(fn() => qb_quote_save($sid, $admin, $sel, [])));
            check('refuse: out of scope (another venue)', $refused(fn() => qb_quote_save($sid, $admin, $sel, [$vid + 100000])));
            check('refuse: nothing priced', $refused(fn() => qb_quote_save($sid, $admin, ['rooms' => [], 'extras' => []] + $sel, null)));
            $refused_msg = ''; try { qb_quote_save($sid, $admin, ['rooms' => [], 'extras' => []] + $sel, null); } catch (QbQuoteRefusal $e) { $refused_msg = $e->getMessage(); }
            check('refuse: wording', $refused_msg === 'Nothing is priced yet.');
            check('refuse: unknown enquiry', $refused(fn() => qb_quote_save(2147483000, $admin, $sel, null)));
            check('refuse: nothing written by refusals', count(qb_quotes_for_submission($sid)) === 2);

            // A priced quote whose total is 0 (100% discount, no extras) is still a quote.
            $free = qb_quote_save($sid, $admin, ['discount_pct' => 100, 'extras' => []] + $sel, null);
            check('save: a 100% discount (total 0) still saves', $free['option_no'] === 3 && $free['total'] == 0.0);
            check('save: the free quote reprints from its snapshot', qb_quote_fetch($free['id'])['snapshot']['quote']['summary']['total'] == 0.0);

            // An enquiry with no property attached is in scope for every account (submission_in_scope()).
            $sid2 = (int)db_query("INSERT INTO submissions (type, guest_name, payload_json) VALUES ('contact', 'No Room', '{}') RETURNING id")->fetchColumn();
            $s3 = qb_quote_save($sid2, null, $sel, [$vid]);
            check('scope: no-property enquiry saves; option numbers are per enquiry', $s3['option_no'] === 1);
            check('fetch: missing id → null', qb_quote_fetch(2147483000) === null);
        }
    } finally { $pdo->rollBack(); }
}

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
