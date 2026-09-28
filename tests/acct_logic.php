<?php
declare(strict_types=1);
// Accounting P2a — folio invoices, payments, allocation, credit notes, refunds, locks.
// Run: php tests/acct_logic.php
// Pure rules always run. The DB block runs inside ONE transaction that is rolled
// back, and SKIPs when no database is reachable or add_acct_documents.sql is missing.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/acct.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}
function refused(callable $fn): bool {
    try { $fn(); return false; } catch (AcctRefusal $e) { return true; }
}

// ── VAT ─────────────────────────────────────────────────────────────────────
check('vat: inclusive 16% of 116.00 is 16.00', acct_vat_split(11600, 16.0, true) === [10000, 1600, 11600]);
check('vat: exclusive 16% on 100.00 adds 16.00', acct_vat_split(10000, 16.0, false) === [10000, 1600, 11600]);
check('vat: zero rate leaves the amount alone', acct_vat_split(12345, 0.0, true) === [12345, 0, 12345]);
check('vat: inclusive rounds half-up to the cent', acct_vat_split(1000, 16.0, true) === [862, 138, 1000]);

$vatCo   = ['vat_registered' => true,  'prices_include_vat' => true];
$exclCo  = ['vat_registered' => true,  'prices_include_vat' => false];
$noVatCo = ['vat_registered' => false, 'prices_include_vat' => true];
$room = ['description' => 'Stay', 'cents' => 58000, 'category' => 'room', 'source_kind' => 'stay', 'source_id' => 1];
$l = acct_build_line($room, $vatCo);
check('line: VAT company, inclusive → band B, VAT inside the price', $l['tax_band'] === 'B' && $l['gross_cents'] === 58000 && $l['vat_cents'] === 8000 && $l['net_cents'] === 50000);
$l = acct_build_line($room, $exclCo);
check('line: VAT company, exclusive → VAT added on top', $l['gross_cents'] === 67280 && $l['net_cents'] === 58000);
$l = acct_build_line($room, $noVatCo);
check('line: non-VAT company → band D, no VAT', $l['tax_band'] === 'D' && $l['vat_cents'] === 0 && $l['gross_cents'] === 58000);
$l = acct_build_line(['description' => 'Shop', 'cents' => 5000, 'category' => 'retail', 'is_disbursement' => true, 'supplier_company_id' => 9], $vatCo);
check('line: a disbursement is never revenue — band D, no VAT', $l['tax_band'] === 'D' && $l['vat_cents'] === 0 && $l['category'] === 'disbursement' && $l['is_disbursement']);
$l = acct_build_line(['description' => 'Tip', 'cents' => 1000, 'category' => 'tip'], $vatCo);
check('line: a tip carries no VAT', $l['vat_cents'] === 0 && $l['tax_band'] === 'D');
$l = acct_build_line(['description' => 'Spa', 'cents' => 11600, 'category' => 'spa', 'vat_snapshot' => ['rate' => 16.0, 'vat_cents' => 1600]], $noVatCo);
check('line: a POS line keeps the sale\'s own VAT snapshot', $l['vat_cents'] === 1600 && $l['net_cents'] === 10000 && $l['tax_band'] === 'B');
$l = acct_build_line(['description' => 'Odd', 'cents' => 500, 'category' => 'retail', 'vat_snapshot' => ['rate' => 16.0, 'vat_cents' => 900]], $vatCo);
check('line: a snapshot VAT larger than the line is capped', $l['vat_cents'] === 500 && $l['net_cents'] === 0);
check('totals: sum of built lines', acct_totals([['net_cents' => 1, 'vat_cents' => 2, 'gross_cents' => 3], ['net_cents' => 10, 'vat_cents' => 20, 'gross_cents' => 30]]) === ['net' => 11, 'vat' => 22, 'gross' => 33]);

check('category: requests', acct_category_for_addon('tour') === 'activity' && acct_category_for_addon('transfer') === 'transfer'
    && acct_category_for_addon('restaurant') === 'fnb' && acct_category_for_addon('laundry') === 'service' && acct_category_for_addon('zzz') === 'other');
check('category: POS outlets', acct_category_for_outlet('shop') === 'retail' && acct_category_for_outlet('salon_spa') === 'spa'
    && acct_category_for_outlet('kite') === 'activity' && acct_category_for_outlet('experiences') === 'activity');
check('money: two decimals with the code', acct_money(123450, 'usd') === 'USD 1,234.50');
check('method: M-Pesa only into a till/paybill', acct_method_fits_account('mpesa', 'mpesa_till') && acct_method_fits_account('mpesa', 'mpesa_paybill') && !acct_method_fits_account('mpesa', 'bank'));
check('method: card into a merchant or bank account, never M-Pesa', acct_method_fits_account('card', 'card_merchant') && acct_method_fits_account('card', 'bank') && !acct_method_fits_account('card', 'mpesa_till'));
check('method: cash into cash or bank; bank transfer/OTA payout into bank', acct_method_fits_account('cash', 'cash') && acct_method_fits_account('cash', 'bank')
    && acct_method_fits_account('bank', 'bank') && !acct_method_fits_account('bank', 'cash') && acct_method_fits_account('ota_payout', 'bank') && !acct_method_fits_account('x', 'bank'));

// ── Allocation plan ─────────────────────────────────────────────────────────
$noRate = fn($a, $b) => null;
$p = acct_allocation_plan([['id' => 1, 'currency' => 'USD', 'balance' => 50000]], [['id' => 7, 'currency' => 'USD', 'available' => 20000]], $noRate);
check('alloc: a part payment applies what it has', count($p) === 1 && $p[0]['amount'] === 20000 && $p[0]['pay_amount'] === 20000);
$p = acct_allocation_plan([['id' => 1, 'currency' => 'USD', 'balance' => 10000], ['id' => 2, 'currency' => 'USD', 'balance' => 10000]],
                          [['id' => 7, 'currency' => 'USD', 'available' => 15000]], $noRate);
check('alloc: oldest invoice first, remainder to the next', $p[0]['document_id'] === 1 && $p[0]['amount'] === 10000 && $p[1]['document_id'] === 2 && $p[1]['amount'] === 5000);
$p = acct_allocation_plan([['id' => 1, 'currency' => 'USD', 'balance' => 10000]],
                          [['id' => 7, 'currency' => 'USD', 'available' => 3000], ['id' => 8, 'currency' => 'USD', 'available' => 9000]], $noRate);
check('alloc: oldest payment first, a later one tops up', $p[0]['payment_id'] === 7 && $p[0]['amount'] === 3000 && $p[1]['payment_id'] === 8 && $p[1]['amount'] === 7000 && $p[1]['pay_amount'] === 7000);
$kes = fn($from, $to) => ($from === 'KES' && $to === 'USD') ? 129.0 : null;
$p = acct_allocation_plan([['id' => 1, 'currency' => 'USD', 'balance' => 10000]], [['id' => 7, 'currency' => 'KES', 'available' => 2000000]], $kes);
check('alloc: KES pays a USD invoice at the rate (USD 100 = KES 12,900)', $p[0]['amount'] === 10000 && $p[0]['pay_amount'] === 1290000 && $p[0]['rate'] === 129.0);
$p = acct_allocation_plan([['id' => 1, 'currency' => 'USD', 'balance' => 10000]], [['id' => 7, 'currency' => 'KES', 'available' => 645000]], $kes);
check('alloc: a KES part payment covers its USD worth', $p[0]['pay_amount'] === 645000 && $p[0]['amount'] === 5000);
$p = acct_allocation_plan([['id' => 1, 'currency' => 'USD', 'balance' => 10000]], [['id' => 7, 'currency' => 'EUR', 'available' => 10000]], $noRate);
check('alloc: no exchange rate → nothing applied (never guessed)', $p === []);
$p = acct_allocation_plan([['id' => 1, 'currency' => 'USD', 'balance' => 0]], [['id' => 7, 'currency' => 'USD', 'available' => 100]], $noRate);
check('alloc: a settled invoice takes nothing', $p === []);

$sum = acct_documents_summary([
    ['company_name' => 'Z', 'currency' => 'USD', 'doc_type' => 'invoice', 'subtotal' => '100', 'vat_amount' => '16', 'total' => '116'],
    ['company_name' => 'Z', 'currency' => 'USD', 'doc_type' => 'credit_note', 'subtotal' => '100', 'vat_amount' => '16', 'total' => '116'],
    ['company_name' => 'Z', 'currency' => 'KES', 'doc_type' => 'invoice', 'subtotal' => '1000', 'vat_amount' => '0', 'total' => '1000'],
]);
check('summary: per company per currency, credit notes subtract', count($sum) === 2 && $sum[1]['currency'] === 'USD' && $sum[1]['total'] === 0 && $sum[0]['total'] === 100000);

// ── DB block ────────────────────────────────────────────────────────────────
try { db()->query('SELECT 1'); }
catch (Throwable $e) {
    echo "\nSKIP  DB block (database unavailable: " . $e->getMessage() . ")\n";
    echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n"; exit($failures ? 1 : 0);
}
if (!acct_supported()) {
    echo "\nSKIP  DB block (add_acct_documents.sql not applied)\n";
    echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n"; exit($failures ? 1 : 0);
}

db()->beginTransaction();
try {
    $ins = function (string $sql, array $a = []): int { db_query($sql, $a); return (int) db()->lastInsertId(); };
    $d = fn(int $days) => date('Y-m-d', strtotime("{$days} days"));
    $sfx = substr(bin2hex(random_bytes(3)), 0, 4);
    $unit = db_query('SELECT u.id, r.venue_id FROM units u JOIN rooms r ON r.id = u.room_id WHERE u.is_active AND r.venue_id IS NOT NULL ORDER BY u.id LIMIT 1')->fetch();
    if (!$unit) throw new RuntimeException('no active unit with a venue to test on');
    $venueId = (int)$unit['venue_id'];

    [$cd] = company_clean(['name' => "ZZ Prop {$sfx}", 'code' => "P{$sfx}", 'kra_pin' => 'P000000001Z', 'vat_registered' => '1']);
    $co = company_save(null, $cd);
    company_set_venue($venueId, $co);
    [$ad] = company_account_clean(['label' => 'Bank USD', 'kind' => 'bank', 'currency' => 'USD', 'bank_name' => 'KCB', 'account_number' => '11112222']);
    $accUsd = company_account_save($co, null, $ad);
    [$ad] = company_account_clean(['label' => 'Till', 'kind' => 'mpesa_till', 'currency' => 'KES', 'account_number' => '555111']);
    $accKes = company_account_save($co, null, $ad);
    [$od] = company_clean(['name' => "ZZ Other {$sfx}", 'code' => "O{$sfx}"]);
    $other = company_save(null, $od);
    [$ad] = company_account_clean(['label' => 'Other cash', 'kind' => 'cash', 'currency' => 'USD']);
    $accOther = company_account_save($other, null, $ad);

    $hold = $ins("INSERT INTO holds (unit_id, check_in, check_out, guest_name, guest_email, status, expires_at)
                  VALUES (:u, :ci, :co, 'ZZ Ada Guest', 'zz@example.com', 'confirmed', NULL)", [':u' => $unit['id'], ':ci' => $d(-2), ':co' => $d(1)]);
    db_query("INSERT INTO bookings (venue_id, source, guest_name, check_in, check_out, nights, gross_amount, currency, status, hold_id)
              VALUES (:v, 'website', 'ZZ Ada Guest', :ci, :co, 3, 580.00, 'USD', 'confirmed', :h)",
        [':v' => $venueId, ':ci' => $d(-2), ':co' => $d(1), ':h' => $hold]);

    // Not live until the start date is set.
    $ctx = acct_hold_context($hold);
    check('db: not live without a start date', !$ctx['live'] && str_contains($ctx['reason'], 'not live'));
    check('db: a payment is refused while not live', refused(fn() => acct_record_payment($hold, ['method' => 'cash', 'amount' => '10', 'account_id' => $accUsd], null)));
    check('db: go-live refused without a KRA PIN', refused(fn() => acct_set_company_invoicing($other, $d(-1), true)));
    [$nd] = company_clean(['name' => "ZZ NoAcc {$sfx}", 'code' => "N{$sfx}", 'kra_pin' => 'P000000002Z']);
    $noAcc = company_save(null, $nd);
    check('db: go-live refused without a money account', refused(fn() => acct_set_company_invoicing($noAcc, $d(-1), true)));
    check('db: go-live accepted with PIN + account', !refused(fn() => acct_set_company_invoicing($co, $d(5), true)));
    db_query('UPDATE companies SET accounting_starts_on = :d WHERE id = :c', [':d' => $d(5), ':c' => $co]);
    check('db: a stay checking out before the start date stays out', !acct_hold_context($hold)['live']);
    db_query('UPDATE companies SET accounting_starts_on = :d WHERE id = :c', [':d' => $d(-30), ':c' => $co]);
    check('db: live once the start date covers the check-out', acct_hold_context($hold)['live']);

    // Charges: a priced request, an unpriced one, a manual bill line.
    $tourAddon = $ins("INSERT INTO booking_addons (hold_id, kind, details, status, price_amount) VALUES (:h, 'transfer', 'Airport pickup', 'confirmed', 40.00)", [':h' => $hold]);
    $unpriced  = $ins("INSERT INTO booking_addons (hold_id, kind, details, status) VALUES (:h, 'other', 'Birthday cake', 'confirmed')", [':h' => $hold]);
    $minibar   = $ins("INSERT INTO bill_items (hold_id, label, amount, currency) VALUES (:h, 'Minibar', 23.20, 'USD')", [':h' => $hold]);

    $f = acct_folio($hold);
    check('db: folio lists the stay + priced request + bill line', count($f['lines']) === 3 && $f['by_cur']['USD']['open'] === 58000 + 4000 + 2320);
    check('db: an unpriced request blocks issuing', count($f['blockers']) === 1 && refused(fn() => acct_issue_folio($hold, null)));
    db_query('UPDATE booking_addons SET price_amount = 0 WHERE id = :a', [':a' => $unpriced]);
    check('db: price 0 = complimentary, no line, no blocker', !acct_folio($hold)['blockers'] && count(acct_folio($hold)['lines']) === 3);

    // A deposit before check-out (prepayment) + a security deposit.
    check('db: an account of another company is refused', refused(fn() => acct_record_payment($hold, ['method' => 'cash', 'amount' => '10', 'account_id' => $accOther], null)));
    check('db: card into an M-Pesa till is refused', refused(fn() => acct_record_payment($hold, ['method' => 'card', 'amount' => '10', 'account_id' => $accKes], null)));
    check('db: M-Pesa needs its confirmation code', refused(fn() => acct_record_payment($hold, ['method' => 'mpesa', 'amount' => '100', 'account_id' => $accKes], null)));
    check('db: a future date is refused', refused(fn() => acct_record_payment($hold, ['method' => 'cash', 'amount' => '1', 'account_id' => $accUsd, 'received_on' => $d(2)], null)));
    $pre = acct_record_payment($hold, ['method' => 'bank', 'amount' => '300', 'account_id' => $accUsd, 'reference' => 'TT123'], null);
    $sec = acct_record_payment($hold, ['method' => 'card', 'amount' => '200', 'account_id' => $accUsd, 'is_security_deposit' => '1'], null);
    $f = acct_folio($hold);
    check('db: prepayment reduces what is due; the security deposit does not', $f['by_cur']['USD']['unapplied'] === 30000 && $f['by_cur']['USD']['deposits'] === 20000
        && $f['by_cur']['USD']['due'] === 64320 - 30000);

    // Issue.
    $num0 = company_sequences($co)['invoice']['next_no'];
    $docs = acct_issue_folio($hold, null, 'a012345678b');
    $doc  = db_query('SELECT * FROM acct_documents WHERE id = :d', [':d' => $docs[0]])->fetch();
    check('db: one invoice for one currency, gapless number', count($docs) === 1 && $doc['number'] === acct_format_number(strtoupper("P{$sfx}-INV-"), (int)$num0));
    check('db: invoice totals — VAT inside the prices (16%)', acct_cents($doc['total']) === 64320 && acct_cents($doc['vat_amount']) === 8872 && acct_cents($doc['subtotal']) === 55448);
    check('db: buyer PIN stored normalised', $doc['customer_pin'] === 'A012345678B' && $doc['customer_kind'] === 'guest');
    check('db: the prepayment was applied at issue', (float) db_query('SELECT SUM(amount) FROM acct_allocations WHERE document_id = :d', [':d' => $doc['id']])->fetchColumn() === 300.0);
    $f = acct_folio($hold);
    check('db: nothing open after issuing; balance = total − prepayment', !$f['lines'] && $f['by_cur']['USD']['due'] === 64320 - 30000 && $f['by_cur']['USD']['invoiced_due'] === 34320);
    check('db: invoiced lines are locked', acct_bill_item_locked($minibar) && acct_addon_locked($tourAddon) && acct_stay_invoiced($hold));
    check('db: issuing again with nothing new is refused', refused(fn() => acct_issue_folio($hold, null)));
    check('db: go-live date locked once invoices exist', refused(fn() => acct_set_company_invoicing($co, $d(-60), true))
        && !refused(fn() => acct_set_company_invoicing($co, $d(-30), true)));
    check('db: a malformed buyer PIN is refused', refused(fn() => acct_issue_folio($hold, null, '123')));

    // A later charge goes on a second invoice; a KES payment settles USD at the rate.
    $late = $ins("INSERT INTO bill_items (hold_id, label, amount, currency) VALUES (:h, 'Late checkout', 50.00, 'USD')", [':h' => $hold]);
    check('db: a new charge after issuing shows as open', acct_folio($hold)['by_cur']['USD']['open'] === 5000);
    $docs2 = acct_issue_folio($hold, null);
    check('db: the second invoice takes the next number', db_query('SELECT number FROM acct_documents WHERE id = :d', [':d' => $docs2[0]])->fetchColumn() === acct_format_number(strtoupper("P{$sfx}-INV-"), (int)$num0 + 1));
    $rate = acct_fx_rate('KES', 'USD');
    if ($rate) {
        $kesDue = (int) round((34320 + 5000) * $rate);
        acct_record_payment($hold, ['method' => 'mpesa', 'amount' => (string)($kesDue / 100), 'account_id' => $accKes, 'reference' => 'QX12AB'], null);
        $f = acct_folio($hold);
        check('db: a KES payment settles both USD invoices at the day\'s rate', $f['by_cur']['USD']['due'] === 0 && $f['by_cur']['USD']['invoiced_due'] === 0);
    } else {
        echo "SKIP  KES→USD settlement (no FX rate configured)\n";
    }

    // Refunds.
    check('db: a fully applied payment cannot be refunded', refused(fn() => acct_refund_payment($pre, '1', 'mistake', null)));
    check('db: a refund needs a reason', refused(fn() => acct_refund_payment($sec, '200', '', null)));
    check('db: refund capped at the security deposit', refused(fn() => acct_refund_payment($sec, '250', 'returned at checkout', null)));
    acct_refund_payment($sec, '200', 'returned at checkout', null);
    check('db: security deposit returned', (acct_folio($hold)['by_cur']['USD']['deposits'] ?? 0) === 0);

    // Credit note: releases lines + payments by appending rows.
    check('db: a credit note needs a reason', refused(fn() => acct_credit_invoice($docs[0], '', null)));
    $cn = acct_credit_invoice($docs[0], 'Wrong minibar amount', null);
    $cnRow = db_query('SELECT * FROM acct_documents WHERE id = :d', [':d' => $cn])->fetch();
    check('db: credit note numbered in its own series, mirrors the total', $cnRow['doc_type'] === 'credit_note' && str_contains($cnRow['number'], '-CN-')
        && $cnRow['total'] === $doc['total'] && (int)$cnRow['credits_document_id'] === (int)$doc['id']);
    check('db: credited lines are released onto the open folio', !acct_bill_item_locked($minibar) && !acct_addon_locked($tourAddon) && !acct_stay_invoiced($hold));
    check('db: an invoice is credited only once', refused(fn() => acct_credit_invoice($docs[0], 'again', null)));
    check('db: a credit note cannot itself be credited', refused(fn() => acct_credit_invoice($cn, 'x', null)));
    $alloc = (float) db_query('SELECT COALESCE(SUM(amount),0) FROM acct_allocations WHERE document_id = :d', [':d' => $doc['id']])->fetchColumn();
    check('db: the credited invoice has no money left on it (negative rows appended)', abs($alloc) < 0.001
        && (int) db_query('SELECT COUNT(*) FROM acct_allocations WHERE document_id = :d AND amount < 0', [':d' => $doc['id']])->fetchColumn() >= 1);
    $f = acct_folio($hold);
    check('db: after the credit the released payment sits unapplied against the re-opened charges',
        $f['by_cur']['USD']['open'] === 64320 && $f['by_cur']['USD']['due'] === 64320 - $f['by_cur']['USD']['unapplied']);
    db_query('UPDATE bill_items SET amount = 13.20 WHERE id = :i', [':i' => $minibar]);
    $docs3 = acct_issue_folio($hold, null);
    $f = acct_folio($hold);
    check('db: re-issued at the corrected amount; money re-applied', acct_cents(db_query('SELECT total FROM acct_documents WHERE id = :d', [':d' => $docs3[0]])->fetchColumn()) === 63320
        && (!$rate || (($f['by_cur']['USD']['invoiced_due'] ?? 0) === 0 && ($f['by_cur']['KES']['unapplied'] ?? 0) > 0)));

    // Agent customer.
    if (holds_agent_supported() && to_regclass_exists('travel_agents')) {
        $ag = $ins("INSERT INTO travel_agents (name, agency, email, password_hash) VALUES ('ZZ Agent', 'ZZ Safaris', :e, 'x')", [':e' => "zz{$sfx}@agent.test"]);
        $h2 = $ins("INSERT INTO holds (unit_id, check_in, check_out, guest_name, guest_email, status, expires_at, agent_id)
                    VALUES (:u, :ci, :co, 'ZZ Traveller', 'a@x.test', 'confirmed', NULL, :ag)", [':u' => $unit['id'], ':ci' => $d(10), ':co' => $d(12), ':ag' => $ag]);
        db_query("INSERT INTO bookings (venue_id, source, guest_name, check_in, check_out, nights, gross_amount, currency, status, hold_id)
                  VALUES (:v, 'agent', 'ZZ Traveller', :ci, :co, 2, 400, 'USD', 'confirmed', :h)", [':v' => $venueId, ':ci' => $d(10), ':co' => $d(12), ':h' => $h2]);
        $ad2 = acct_issue_folio($h2, null);
        $r = db_query('SELECT customer_kind, customer_name, agent_id FROM acct_documents WHERE id = :d', [':d' => $ad2[0]])->fetch();
        check('db: an agent booking is invoiced to the agency', $r['customer_kind'] === 'agent' && str_contains($r['customer_name'], 'ZZ Safaris') && (int)$r['agent_id'] === $ag);
    }

    // A pending booking: the stay waits for confirmation.
    $h3 = $ins("INSERT INTO holds (unit_id, check_in, check_out, guest_name, guest_email, status, expires_at)
                VALUES (:u, :ci, :co, 'ZZ Pending', 'p@x.test', 'pending', NOW() + INTERVAL '1 day')", [':u' => $unit['id'], ':ci' => $d(20), ':co' => $d(22)]);
    $f = acct_folio($h3);
    check('db: a pending booking has no stay line yet (note shown)', !$f['lines'] && $f['notes']);

    // POS room charges: same company → revenue with the sale's VAT + a tip line; another company → pass-through.
    if (to_regclass_exists('pos_sales') && company_column_exists('pos_sales', 'tip_amount') && company_column_exists('pos_outlets', 'company_id')) {
        require_once __DIR__ . '/../includes/pos.php';
        $h5 = $ins("INSERT INTO holds (unit_id, check_in, check_out, guest_name, guest_email, status, expires_at)
                    VALUES (:u, :ci, :co, 'ZZ Pos Guest', 'pg@x.test', 'pending', NOW() + INTERVAL '1 day')", [':u' => $unit['id'], ':ci' => $d(-1), ':co' => $d(2)]);
        $own = $ins("INSERT INTO pos_outlets (name, slug, kind, currency, company_id) VALUES ('ZZ Bar', :s, 'other', 'USD', :c)", [':s' => "zz-bar-{$sfx}", ':c' => $co]);
        $svc = $ins("INSERT INTO pos_outlets (name, slug, kind, currency, company_id) VALUES ('ZZ Spa', :s, 'salon_spa', 'KES', :c)", [':s' => "zz-spa-{$sfx}", ':c' => $other]);
        $noCo = $ins("INSERT INTO pos_outlets (name, slug, kind, currency) VALUES ('ZZ Kiosk', :s, 'shop', 'USD')", [':s' => "zz-kiosk-{$sfx}"]);
        $sale = function (int $outlet, string $cur, float $total, float $vat, float $tip, ?float $fx, float $billAmt) use ($ins, $h5, $sfx): int {
            static $n = 0; $n++;
            $sid = $ins("INSERT INTO pos_sales (reference, outlet_id, customer_type, hold_id, currency, subtotal, total, payment_method, client_uuid,
                                                vat_pct, vat_amount, tip_amount, bill_currency, bill_amount, fx_rate)
                         VALUES (:r, :o, 'inhouse', :h, :cur, :t, :t, 'room_charge', :uu, 16, :vat, :tip, 'USD', :ba, :fx)",
                [':r' => "ZZ-{$sfx}-{$n}", ':o' => $outlet, ':h' => $h5, ':cur' => $cur, ':t' => $total, ':uu' => "zz-{$sfx}-{$n}",
                 ':vat' => $vat, ':tip' => $tip, ':ba' => $billAmt, ':fx' => $fx]);
            $ins("INSERT INTO bill_items (hold_id, label, amount, pos_sale_id, currency) VALUES (:h, :l, :a, :s, 'USD')", [':h' => $h5, ':l' => "ZZ POS {$n}", ':a' => $billAmt, ':s' => $sid]);
            return $sid;
        };
        $s1 = $sale($own, 'USD', 126.00, 16.00, 10.00, null, 126.00);        // goods 116 (VAT 16) + tip 10
        $s2 = $sale($svc, 'KES', 12900.00, 1779.31, 0, 129.0, 100.00);        // another company's spa, KES → USD 100
        $f = acct_folio($h5);
        $bySrc = [];
        foreach ($f['lines'] as $l) $bySrc[] = $l['category'] . ':' . $l['gross_cents'] . ':' . $l['vat_cents'];
        check('db: same-company POS charge keeps the sale VAT and splits the tip', in_array('other:11600:1600', $bySrc, true) && in_array('tip:1000:0', $bySrc, true));
        check('db: another company\'s room charge is a pass-through (no VAT, not revenue)', in_array('disbursement:10000:0', $bySrc, true));
        db_query("UPDATE companies SET room_charge_mode = 'reinvoice' WHERE id = :c", [':c' => $other]);
        $bySrc = array_map(fn($l) => $l['category'] . ':' . $l['gross_cents'] . ':' . $l['vat_cents'], acct_folio($h5)['lines']);
        check('db: in re-invoice mode it is this property\'s revenue under its own VAT', in_array('spa:10000:1379', $bySrc, true));
        db_query("UPDATE companies SET room_charge_mode = 'on_behalf' WHERE id = :c", [':c' => $other]);
        $sale($noCo, 'USD', 5, 0, 0, null, 5);
        check('db: an outlet with no company blocks the invoice (fail closed)', (bool) array_filter(acct_folio($h5)['blockers'], fn($b) => str_contains($b, 'ZZ Kiosk')));
        db_query('UPDATE pos_outlets SET company_id = :c WHERE id = :o', [':c' => $co, ':o' => $noCo]);
        check('db: a pending booking can still invoice its extras', (bool) acct_issue_folio($h5, null));
        check('db: an invoiced POS room charge cannot be voided', pos_bill_line_invoiced($s1) && pos_bill_line_invoiced($s2));
        $uid = (int) db_query("SELECT id FROM admin_users WHERE role = 'owner' ORDER BY id LIMIT 1")->fetchColumn();
        if ($uid) {
            $v = pos_void_sale($s1, 'guest disputed', $uid);
            check('db: …the POS void is refused with a reason', !$v['ok'] && str_contains($v['error'], 'invoice'));
        }
    }

    // Gapless: a failed issue gives the number back.
    $before = company_sequences($co)['invoice']['next_no'];
    $h4 = $ins("INSERT INTO holds (unit_id, check_in, check_out, guest_name, guest_email, status, expires_at)
                VALUES (:u, :ci, :co, 'ZZ Mixed', 'm@x.test', 'confirmed', NULL)", [':u' => $unit['id'], ':ci' => $d(30), ':co' => $d(31)]);
    db_query("INSERT INTO bookings (venue_id, source, guest_name, check_in, check_out, nights, gross_amount, currency, status, hold_id)
              VALUES (:v, 'website', 'ZZ Mixed', :ci, :co, 1, 100, 'USD', 'confirmed', :h)", [':v' => $venueId, ':ci' => $d(30), ':co' => $d(31), ':h' => $h4]);
    $ins("INSERT INTO bill_items (hold_id, label, amount, currency) VALUES (:h, 'Odd', 5, 'XYZ')", [':h' => $h4]);
    check('db: an invoice needing a missing FX rate is refused', refused(fn() => acct_issue_folio($h4, null)));
    check('db: …and its number is not lost', company_sequences($co)['invoice']['next_no'] === $before);
} catch (Throwable $e) {
    check('db block threw: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    if (db()->inTransaction()) db()->rollBack();
}
check('db: the test left no documents behind', (int) db_query("SELECT COUNT(*) FROM acct_documents WHERE customer_name LIKE 'ZZ %' OR customer_name LIKE '%ZZ %'")->fetchColumn() === 0);

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
