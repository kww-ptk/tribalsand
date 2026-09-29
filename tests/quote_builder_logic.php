<?php
declare(strict_types=1);
// Quote Builder — maths, notices, quote text; DB-backed pricing in a rolled-back
// transaction. Run: php tests/quote_builder_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/quote-builder.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}
$fx = ['USD' => 1.0, 'KES' => 129.0];

// ── Extras by basis ──────────────────────────────────────────────────────────
check('extra: per stay = unit × qty',    qb_extra_amount(50.0, 2, 'stay', 4) === 100.0);
check('extra: per night = unit × nights × qty', qb_extra_amount(10.0, 2, 'night', 4) === 80.0);
check('extra: per person = unit × qty',  qb_extra_amount(40.0, 3, 'person', 4) === 120.0);
check('extra: unknown basis = per stay', qb_extra_amount(5.0, 1, 'bogus', 4) === 5.0);

// ── Totals ───────────────────────────────────────────────────────────────────
$t = qb_totals([['amt' => 100000.0, 'cur' => 'KES']], [['amt' => 50.0, 'cur' => 'USD']], 10.0, 'KES', $fx);
check('totals: discount on accommodation only', $t['discount'] === 10000.0);
check('totals: extras converted to the quote currency', $t['extras'] === 6450.0);
check('totals: total = acc − discount + extras', $t['total'] === 96450.0);
check('totals: flags a conversion', $t['converted'] === true);
$t2 = qb_totals([['amt' => 1000.0, 'cur' => 'KES']], [], 0.0, 'KES', $fx);
check('totals: single currency is exact', $t2['converted'] === false && $t2['total'] === 1000.0);
check('totals: discount clamps to 100%', qb_totals([['amt' => 10.0, 'cur' => 'KES']], [], 250.0, 'KES', $fx)['discount'] === 10.0);
$t3 = qb_totals([['amt' => 10.0, 'cur' => 'EUR']], [], 0.0, 'KES', $fx);
check('totals: a missing rate is reported, never summed as 0', $t3['missing'] === ['EUR'] && $t3['total'] === 0.0);

// ── Notices ──────────────────────────────────────────────────────────────────
$picked = [
    ['name' => 'Zuri — Maji Suite', 'qty' => 1, 'guests' => 3, 'capacity' => 2, 'free' => 1, 'free_exact' => true],
    ['name' => 'Maya Kobe — Haze Suite', 'qty' => 2, 'guests' => 2, 'capacity' => 4, 'free' => 1, 'free_exact' => true],
];
$ns = array_column(qb_notices($picked, 6, true, []), 'text');
check('notice: over a room\'s capacity', in_array('Zuri — Maji Suite: 3 guests, sleeps 2.', $ns, true));
check('notice: fewer free than asked', in_array('Maya Kobe — Haze Suite: only 1 free for these dates.', $ns, true));
check('notice: rooms sleep less than the party', in_array('The rooms chosen sleep 6; the party is 6.', $ns, true) === false);
check('notice: allocation differs from party', in_array('Guests allocated (5) differ from the party (6).', $ns, true));
$ns2 = array_column(qb_notices([], 2, false, []), 'text');
check('notice: bad dates', in_array('Choose check-in and check-out dates.', $ns2, true));
$ns3 = array_column(qb_notices([['name' => 'X', 'qty' => 1, 'guests' => 2, 'capacity' => 2, 'free' => 0, 'free_exact' => false]], 2, true, ['Sunset dhow']), 'text');
check('notice: composite product not free', in_array('X: not free for these dates.', $ns3, true));
check('notice: unpriced extra', in_array('Sunset dhow: add a price.', $ns3, true));

// ── Quote text ───────────────────────────────────────────────────────────────
$txt = qb_quote_text([
    'name' => 'Sofia Martin', 'check_in' => '2027-03-24', 'check_out' => '2027-03-28', 'nights' => 4,
    'adults' => 2, 'children' => 1, 'currency' => 'KES', 'today' => '2026-09-29',
    'rooms' => [['name' => 'Zuri — Maji Suite', 'qty' => 1, 'mix' => '2 Mid + 2 Peak', 'amt' => 229680.0]],
    'extras' => [['label' => 'Airport → Property', 'qty' => 1, 'amt' => 6450.0],
                 ['label' => 'Private chef dinner', 'qty' => 1, 'basis' => 'night', 'amt' => 400.0],
                 ['label' => 'Tsavo East', 'qty' => 2, 'basis' => 'person', 'amt' => 300.0],
                 ['label' => 'Guide', 'qty' => 1, 'basis' => 'person', 'amt' => 50.0]],
    'discount_pct' => 10.0, 'discount_note' => 'Returning guest', 'discount' => 22968.0,
    'total' => 213162.0, 'fx_note' => 'Converted at 1 USD = 129 KES on 29 Sep 2026.',
]);
check('text: header', str_starts_with($txt, "Tribal Sand — quote for Sofia Martin\n24 Mar 2027 → 28 Mar 2027 · 4 nights · 2 adults, 1 child"));
check('text: room line', str_contains($txt, '• Zuri — Maji Suite × 1 (2 Mid + 2 Peak): KES 229,680'));
check('text: discount line', str_contains($txt, 'Discount 10% (Returning guest): −KES 22,968'));
check('text: extra line', str_contains($txt, '• Airport → Property × 1: KES 6,450'));
check('text: per-night extra shows nights', str_contains($txt, '• Private chef dinner × 1 · 4 nights: KES 400'));
check('text: per-person extra shows people', str_contains($txt, '• Tsavo East × 2 people: KES 300') && str_contains($txt, '• Guide × 1 person: KES 50'));
check('label: basis helper', qb_extra_label('X', 1, 'night', 1) === 'X × 1 · 1 night'
    && qb_extra_label('X', 3, 'trip', 4) === 'X × 3' && qb_extra_label('X', 2, 'stay', 4) === 'X × 2');
check('text: total + fx + validity', str_contains($txt, "Total: KES 213,162\nConverted at 1 USD = 129 KES on 29 Sep 2026.\nPrices valid on 29 Sep 2026; subject to availability until booked."));
check('fx note', qb_fx_note($fx, 'KES', '2026-09-29') === 'Converted at 1 USD = 129 KES on 29 Sep 2026.');

// ── DB: catalogue + pricing (rolled back) ─────────────────────────────────────
$pdo = null;
try { $pdo = db(); } catch (Throwable $e) { echo "SKIP  DB block (no database)\n"; }
if ($pdo) {
    $pdo->beginTransaction();
    try {
        $cat = qb_catalog(null);
        check('catalogue: rooms are published only', !array_filter($cat['rooms'], fn($r) => !qb_bool($r['is_published'] ?? true)));
        check('catalogue: every room has max_qty ≥ 1', !array_filter($cat['rooms'], fn($r) => (int)$r['max_qty'] < 1));
        check('catalogue: empty scope sees nothing', qb_catalog([])['rooms'] === []);

        // First room with a base price (some rooms are legitimately unpriced — see the unpriced block below).
        $room = null;
        foreach ($cat['rooms'] as $cr) if ((float)$cr['price_amount'] > 0) { $room = $cr; break; }
        if (!$room) { echo "SKIP  pricing (no published rooms)\n"; }
        else {
            $rid = (int)$room['id'];
            $sel = ['check_in' => '2099-05-10', 'check_out' => '2099-05-13', 'adults' => 2, 'children' => 0,
                    'cur' => strtoupper((string)$room['price_currency']) === 'USD' ? 'USD' : 'KES',
                    'rooms' => [['id' => $rid, 'qty' => 1, 'guests' => 2]], 'extras' => [], 'want_free' => true];
            $q = qb_price_selection($sel, null);
            $single = room_stay_quote($rid, (float)$room['price_amount'], '2099-05-10', '2099-05-13');
            $line = null;
            foreach ($q['rooms'] as $r) if ($r['id'] === $rid) $line = $r;
            check('pricing: room line == the booking widget quote', $line && $line['line'] && abs($line['line']['amt'] - $single['total']) < 0.001);
            check('pricing: nights', $q['nights'] === 3);
            check('pricing: every catalogue room is priced (avg)', count($q['rooms']) === count($cat['rooms']));
            check('pricing: free computed when asked', array_key_exists('free', $line));

            $foreign = qb_price_selection(['rooms' => [['id' => $rid, 'qty' => 1, 'guests' => 2]]] + $sel, []);
            check('pricing: out-of-scope room is dropped', $foreign['rooms'] === [] && $foreign['summary']['accommodation'] === 0.0);

            $bad = qb_price_selection(['check_in' => '2099-05-13', 'check_out' => '2099-05-10'] + $sel, null);
            check('pricing: bad dates → no room lines, a notice', $bad['nights'] === 0
                && in_array('Choose check-in and check-out dates.', array_column($bad['notices'], 'text'), true));

            $cust = qb_price_selection(['extras' => [['key' => 'x1', 'kind' => 'custom', 'label' => 'Private chef',
                'qty' => 2, 'price' => 100, 'price_cur' => 'USD', 'basis' => 'night']]] + $sel, null);
            $x = $cust['extras'][0];
            check('pricing: custom per-night extra', $x['line']['amt'] === 600.0 && $x['line']['cur'] === 'USD');
            check('pricing: copy text present', str_contains($cust['text'], 'Private chef × 2 · 3 nights'));
            check('pricing: breakdown label carries the basis',
                in_array('Private chef × 2 · 3 nights', array_column($cust['lines'], 'label'), true));

            // An unpriced room (no base price, no override in the stay) must never be quoted at 0.
            $zr = null;
            foreach ($cat['rooms'] as $cr) if ((int)$cr['id'] !== $rid && (float)$cr['price_amount'] > 0) { $zr = $cr; break; }
            if (!$zr) { echo "SKIP  unpriced room (need a second priced room)\n"; }
            else {
                $zid = (int)$zr['id'];
                db_query('UPDATE rooms SET price_amount = 0 WHERE id = :i', [':i' => $zid]);
                db_query("DELETE FROM rates WHERE room_id = :i AND date_from < '2100-01-01' AND date_to > '2099-01-01'", [':i' => $zid]);
                // qb_catalog() memoises per scope key, so use a scope (both rooms' venues) not yet cached
                // — a fresh read sees the UPDATE above.
                $zscope = array_values(array_unique([(int)$room['venue_id'], (int)$zr['venue_id']]));
                sort($zscope);
                $zsel = ['rooms' => [['id' => $rid, 'qty' => 1, 'guests' => 2], ['id' => $zid, 'qty' => 1, 'guests' => 2]]] + $sel;
                $zq = qb_price_selection($zsel, $zscope);
                $zrow = null; $orow = null;
                foreach ($zq['rooms'] as $r) { if ($r['id'] === $zid) $zrow = $r; if ($r['id'] === $rid) $orow = $r; }
                $zmsg = $zr['venue_name'] . ' — ' . $zr['name'] . ': no price set for these dates.';
                check('unpriced: line and avg are null (never 0)', $zrow && $zrow['line'] === null && $zrow['avg'] === null);
                check('unpriced: a warn notice names the room', in_array(['type' => 'warn', 'text' => $zmsg], $zq['notices'], true));
                $only = qb_price_selection(['rooms' => [['id' => $rid, 'qty' => 1, 'guests' => 2]]] + $sel, $zscope);
                check('unpriced: total excludes it (== the priced room alone)',
                    abs($zq['summary']['accommodation'] - $only['summary']['accommodation']) < 0.005
                    && $zq['summary']['total'] === $only['summary']['total']);
                check('unpriced: kept out of the quote text', !str_contains($zq['text'], $zr['venue_name'] . ' — ' . $zr['name']));
                $zq0 = qb_price_selection(['rooms' => [['id' => $zid, 'qty' => 0, 'guests' => 0]]] + $sel, $zscope);
                check('unpriced: no notice when the room is not picked', !in_array(['type' => 'warn', 'text' => $zmsg], $zq0['notices'], true));
            }
        }
    } finally { $pdo->rollBack(); }
}

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
