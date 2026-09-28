<?php
declare(strict_types=1);
// Accounting P2b — POS sale documents, inter-company ledger, stock transfers between
// companies, partial credit notes, deposits, confirm/cancel hooks.
// Run: php tests/acct_ops_logic.php
// Pure rules always run. The DB block drives REAL POS sales / stock moves inside ONE
// transaction that is rolled back; SKIPs with no DB or without add_acct_p2b.sql.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/acct.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}
function refused(callable $fn): bool {
    try { $fn(); return false; } catch (AcctRefusal|CompanyRefusal $e) { return true; }
}

// ── POS sale → document lines ───────────────────────────────────────────────
$sum = fn(array $ls, string $k) => array_sum(array_column($ls, $k));
$sale = ['subtotal' => '2320', 'service_charge' => '0', 'vat_pct' => '16', 'vat_amount' => '320', 'vat_inclusive' => true, 'tip_amount' => '0', 'total' => '2320'];
$ls = acct_pos_document_lines($sale, [['id' => 1, 'name' => 'Tee', 'qty' => 2, 'line_total' => '2320', 'category' => 'retail']]);
check('pos lines: inclusive VAT stays inside the price', count($ls) === 1 && $ls[0]['gross_cents'] === 232000 && $ls[0]['vat_cents'] === 32000 && $ls[0]['description'] === 'Tee × 2');
$sale = ['subtotal' => '100', 'service_charge' => '10', 'vat_pct' => '16', 'vat_amount' => '17.60', 'vat_inclusive' => false, 'tip_amount' => '5', 'total' => '132.60'];
$ls = acct_pos_document_lines($sale, [['id' => 1, 'name' => 'Lesson', 'qty' => 1, 'line_total' => '100', 'category' => 'activity']]);
check('pos lines: exclusive VAT added, service charge its own line, tip non-revenue',
    count($ls) === 3 && $ls[1]['category'] === 'service' && $ls[2]['category'] === 'tip' && $ls[2]['vat_cents'] === 0
    && $sum($ls, 'gross_cents') === 13260 && $sum($ls, 'vat_cents') === 1760);
$sale = ['subtotal' => '0.03', 'service_charge' => '0', 'vat_pct' => '16', 'vat_amount' => '0.01', 'vat_inclusive' => true, 'tip_amount' => '0', 'total' => '0.03'];
$ls = acct_pos_document_lines($sale, [['id' => 1, 'name' => 'A', 'qty' => 1, 'line_total' => '0.01'], ['id' => 2, 'name' => 'B', 'qty' => 1, 'line_total' => '0.01'], ['id' => 3, 'name' => 'C', 'qty' => 1, 'line_total' => '0.01']]);
check('pos lines: rounding — the lines add up to the sale\'s own VAT exactly', $sum($ls, 'vat_cents') === 1 && $sum($ls, 'gross_cents') === 3);
$sale = ['subtotal' => '50', 'service_charge' => '0', 'vat_pct' => '0', 'vat_amount' => '0', 'vat_inclusive' => true, 'tip_amount' => '0', 'total' => '50'];
$ls = acct_pos_document_lines($sale, [['id' => 1, 'name' => 'X', 'qty' => 1, 'line_total' => '50']]);
check('pos lines: a no-VAT outlet → band D', $ls[0]['tax_band'] === 'D' && $ls[0]['vat_cents'] === 0);
check('pos method map', acct_pos_method('cash') === 'cash' && acct_pos_method('card') === 'card' && acct_pos_method('mobile_money') === 'mpesa'
    && acct_pos_method('other') === null && acct_pos_method('room_charge') === null);

// ── Inter-company netting ───────────────────────────────────────────────────
$net = acct_ic_net([['f' => 1, 't' => 2, 'currency' => 'USD', 'amt' => '100'], ['f' => 2, 't' => 1, 'currency' => 'USD', 'amt' => '30'],
                    ['f' => 3, 't' => 1, 'currency' => 'KES', 'amt' => '500'], ['f' => 1, 't' => 3, 'currency' => 'KES', 'amt' => '-500'],
                    ['f' => 2, 't' => 3, 'currency' => 'USD', 'amt' => '10'], ['f' => 3, 't' => 2, 'currency' => 'USD', 'amt' => '25']]);
check('ic net: opposite amounts net per pair', in_array(['debtor' => 1, 'creditor' => 2, 'currency' => 'USD', 'cents' => 7000], $net, true));
check('ic net: direction flips when the other side owes more', in_array(['debtor' => 3, 'creditor' => 2, 'currency' => 'USD', 'cents' => 1500], $net, true));
check('ic net: a settled pair disappears', !array_filter($net, fn($r) => $r['currency'] === 'KES') || in_array(['debtor' => 3, 'creditor' => 1, 'currency' => 'KES', 'cents' => 100000], $net, true));
check('ic net: scoped to companies', count(acct_ic_net([['f' => 1, 't' => 2, 'currency' => 'USD', 'amt' => '1'], ['f' => 3, 't' => 4, 'currency' => 'USD', 'amt' => '1']], [3])) === 1);

// ── DB block ────────────────────────────────────────────────────────────────
try { db()->query('SELECT 1'); }
catch (Throwable $e) {
    echo "\nSKIP  DB block (database unavailable: " . $e->getMessage() . ")\n";
    echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n"; exit($failures ? 1 : 0);
}
if (!acct_ops_supported() || !to_regclass_exists('pos_sales') || !company_column_exists('pos_sales', 'tip_amount') || !inv_ops_ready()) {
    echo "\nSKIP  DB block (add_acct_p2b.sql / POS v2 / inventory not applied)\n";
    echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n"; exit($failures ? 1 : 0);
}
function inv_ops_ready(): bool { return to_regclass_exists('inv_moves') && company_column_exists('inv_moves', 'transfer_ref') && company_column_exists('pos_outlets', 'company_id'); }

require_once __DIR__ . '/../includes/pos.php';
require_once __DIR__ . '/../includes/inventory.php';

db()->beginTransaction();
try {
    $ins = function (string $sql, array $a = []): int { db_query($sql, $a); return (int) db()->lastInsertId(); };
    $d   = fn(int $days) => date('Y-m-d', strtotime("{$days} days"));
    $sfx = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
    db_query('UPDATE companies SET accounting_starts_on = NULL');   // isolate from whatever is live on this DB
    db_query("UPDATE pos_outlets SET is_active = FALSE");            // only the test's own tills are open

    $mkCo = function (string $name, string $code, string $pin) {
        [$c] = company_clean(['name' => $name, 'code' => $code, 'kra_pin' => $pin, 'vat_registered' => '1']);
        return company_save(null, $c);
    };
    $prop = $mkCo("ZZ Prop {$sfx}", "P{$sfx}", 'P000000011A');
    $svc  = $mkCo("ZZ Services {$sfx}", "S{$sfx}", 'P000000012B');
    $acc  = function (int $co, array $f) { [$a] = company_account_clean($f); return company_account_save($co, null, $a); };
    $svcCash = $acc($svc, ['label' => 'Till cash', 'kind' => 'cash', 'currency' => 'KES']);
    $acc($svc, ['label' => 'Till M-Pesa', 'kind' => 'mpesa_till', 'currency' => 'KES', 'account_number' => '777111']);
    $svcBank = $acc($svc, ['label' => 'Svc bank', 'kind' => 'bank', 'currency' => 'KES', 'bank_name' => 'KCB', 'account_number' => '9999000011']);
    $propBank = $acc($prop, ['label' => 'Prop bank USD', 'kind' => 'bank', 'currency' => 'USD', 'bank_name' => 'Equity', 'account_number' => '1111222233']);

    $unit = db_query('SELECT u.id, r.venue_id FROM units u JOIN rooms r ON r.id = u.room_id WHERE u.is_active AND r.venue_id IS NOT NULL ORDER BY u.id LIMIT 1')->fetch();
    $venue = (int)$unit['venue_id'];
    company_set_venue($venue, $prop);
    $owner = $ins("INSERT INTO admin_users (email, role, name, is_active) VALUES (:e, 'owner', 'ZZ Owner', TRUE)", [':e' => "zz-owner-{$sfx}@x.test"]);
    $_SESSION['admin_id'] = $owner; $_SESSION['admin_role'] = 'owner';

    $out = $ins("INSERT INTO pos_outlets (name, slug, kind, currency, vat_pct, vat_inclusive, tips_enabled, require_signature, company_id)
                 VALUES ('ZZ Spa', :s, 'salon_spa', 'KES', 16, TRUE, TRUE, FALSE, :c)", [':s' => "zz-spa-{$sfx}", ':c' => $svc]);
    $bare = $ins("INSERT INTO pos_outlets (name, slug, kind, currency, require_signature) VALUES ('ZZ Kiosk', :s, 'shop', 'KES', FALSE)", [':s' => "zz-kiosk-{$sfx}"]);
    $massage = $ins("INSERT INTO pos_items (outlet_id, name, kind, price) VALUES (:o, 'ZZ Massage', 'service', 1160)", [':o' => $out]);
    $gum = $ins("INSERT INTO pos_items (outlet_id, name, kind, price) VALUES (:o, 'ZZ Gum', 'product', 100)", [':o' => $bare]);
    $n = 0;
    $sell = function (int $outlet, int $item, int $qty, string $pay, array $cust = ['type' => 'walkin']) use ($owner, $sfx, &$n) {
        $n++;
        return pos_complete_sale(['outlet_id' => $outlet, 'client_uuid' => sprintf('%08x-zz%02d-4000-8000-%012x', crc32($sfx), $n % 100, $n),
                                  'lines' => [['item_id' => $item, 'qty' => $qty]], 'payment_method' => $pay, 'customer' => $cust], $owner);
    };
    $docOf = fn(int $saleId, string $type = 'invoice') => db_query("SELECT d.*, " . acct_doc_status_sql('d') . " FROM acct_documents d WHERE d.pos_sale_id = :s AND d.doc_type = :t",
        [':s' => $saleId, ':t' => $type])->fetch();

    // Before go-live nothing is issued.
    $r = $sell($out, $massage, 1, 'cash');
    check('pos: before go-live a sale issues no document', $r['ok'] && !$docOf((int)$r['sale']['id']));

    db_query('UPDATE companies SET accounting_starts_on = :d WHERE id IN (:a, :b)', [':d' => $d(-10), ':a' => $prop, ':b' => $svc]);

    // Walk-in cash sale → invoice paid into the till cash account.
    $r = $sell($out, $massage, 2, 'cash');
    $sid = (int)$r['sale']['id'];
    $doc = acct_doc_with_balance($docOf($sid));
    check('pos: a walk-in sale issues the services company\'s tax invoice', $r['ok'] && $doc && (int)$doc['company_id'] === $svc
        && acct_cents($doc['total']) === 232000 && acct_cents($doc['vat_amount']) === 32000 && $doc['customer_kind'] === 'walkin');
    check('pos: numbered in the services company\'s series', str_starts_with($doc['number'], "S{$sfx}-INV-"));
    $pay = db_query('SELECT * FROM acct_payments WHERE pos_sale_id = :s', [':s' => $sid])->fetch();
    check('pos: paid at once into the matching till account', $pay && (int)$pay['account_id'] === $svcCash && $doc['balance_cents'] === 0);

    // Void → credit note + refund.
    $v = pos_void_sale($sid, 'rang up twice', $owner);
    $doc = acct_doc_with_balance($docOf($sid));
    check('pos void: the invoice is credited in full', $v['ok'] && $doc['fully_credited']);
    check('pos void: the till money is refunded', acct_payment_available((int)$pay['id']) === 0
        && (bool) db_query("SELECT 1 FROM acct_payments WHERE refunds_payment_id = :p AND kind = 'refund'", [':p' => $pay['id']])->fetchColumn());

    // Fail closed: an outlet with no company can't sell once invoicing is live.
    $r = $sell($bare, $gum, 1, 'cash');
    check('pos: an outlet with no company refuses the sale once invoicing is live', !$r['ok'] && str_contains($r['error'], 'no company'));
    check('pos: …and wrote nothing', !(bool) db_query("SELECT 1 FROM pos_sales WHERE outlet_id = :o", [':o' => $bare])->fetchColumn());
    db_query('UPDATE pos_outlets SET is_active = FALSE WHERE id = :o', [':o' => $bare]);

    // In-house guest at the property (company Prop), room-charging at the Svc spa.
    $hold = $ins("INSERT INTO holds (unit_id, check_in, check_out, guest_name, guest_email, status, expires_at)
                  VALUES (:u, :ci, :co, 'ZZ Ada Guest', 'zz@x.test', 'confirmed', NULL)", [':u' => $unit['id'], ':ci' => $d(-1), ':co' => $d(2)]);
    db_query("INSERT INTO bookings (venue_id, source, guest_name, check_in, check_out, nights, gross_amount, currency, status, hold_id)
              VALUES (:v, 'website', 'ZZ Ada Guest', :ci, :co, 3, 300, 'USD', 'confirmed', :h)", [':v' => $venue, ':ci' => $d(-1), ':co' => $d(2), ':h' => $hold]);
    $inhouse = ['type' => 'inhouse', 'hold_id' => $hold];
    $rate = acct_fx_rate('KES', 'USD');
    if (!$rate) throw new RuntimeException('no KES/USD rate on this DB');

    // on_behalf: the spa invoices the guest; the folio carries it as a pass-through.
    $r = $sell($out, $massage, 1, 'room_charge', $inhouse);
    $rc = (int)$r['sale']['id'];
    $svcDoc = acct_doc_with_balance($docOf($rc));
    check('room charge (on behalf): the spa\'s company invoices the guest, unpaid for now', $r['ok'] && $svcDoc && (int)$svcDoc['company_id'] === $svc
        && $svcDoc['customer_kind'] === 'guest' && $svcDoc['hold_id'] === null && $svcDoc['balance_cents'] === 116000);
    $f = acct_folio($hold);
    $disb = array_values(array_filter($f['lines'], fn($l) => $l['is_disbursement']));
    check('room charge (on behalf): on the folio as a pass-through line', count($disb) === 1 && $disb[0]['vat_cents'] === 0 && (int)$disb[0]['supplier_company_id'] === $svc);
    $inv = acct_issue_folio($hold, $owner)[0];
    $bal = acct_ic_balances([$prop, $svc]);
    $usd = (int) round(116000 / $rate);
    check('folio issue: the property now owes the spa company (USD, at the sale\'s rate)',
        count($bal) === 1 && $bal[0]['debtor'] === $prop && $bal[0]['creditor'] === $svc && $bal[0]['currency'] === 'USD' && abs($bal[0]['cents'] - $usd) <= 1);
    check('folio issue: the spa\'s own invoice is settled by an inter-company receipt', acct_doc_with_balance($docOf($rc))['balance_cents'] === 0
        && (bool) db_query("SELECT 1 FROM acct_payments WHERE pos_sale_id = :s AND method = 'intercompany' AND counterparty_company_id = :p", [':s' => $rc, ':p' => $prop])->fetchColumn());
    check('pos void: an invoiced room charge can\'t be voided', !pos_void_sale($rc, 'x', $owner)['ok']);

    // Partial credit: only the pass-through line.
    $lines = acct_document_lines_status($inv);
    $dl = array_values(array_filter($lines, fn($l) => companies_bool($l['is_disbursement'])))[0];
    $cn = acct_credit_document($inv, 'Spa charge disputed', $owner, [(int)$dl['id']]);
    $invRow = acct_doc_with_balance(db_query('SELECT d.*, ' . acct_doc_status_sql('d') . ' FROM acct_documents d WHERE d.id = :d', [':d' => $inv])->fetch());
    check('partial credit: only the ticked line is credited', !$invRow['fully_credited'] && $invRow['credited_cents'] === acct_cents($dl['line_total'])
        && acct_stay_invoiced($hold));
    check('partial credit: that charge is back on the folio', count(array_filter(acct_folio($hold)['lines'], fn($l) => $l['is_disbursement'])) === 1);
    check('partial credit: the inter-company amount is reversed', acct_ic_balances([$prop, $svc]) === []);
    check('partial credit: the spa invoice is owed again', acct_doc_with_balance($docOf($rc))['balance_cents'] === 116000);
    check('partial credit: a credited line can\'t be credited twice', refused(fn() => acct_credit_document($inv, 'again', $owner, [(int)$dl['id']])));
    check('partial credit: ticking nothing is refused', refused(fn() => acct_credit_document($inv, 'nothing', $owner, [])));
    $pos = pos_void_sale($rc, 'guest changed mind', $owner);
    check('pos void: once released, the room charge voids — and its own invoice is credited', $pos['ok'] && acct_doc_with_balance($docOf($rc))['fully_credited']);

    // reinvoice: the spa company invoices the property company instead.
    db_query("UPDATE companies SET room_charge_mode = 'reinvoice' WHERE id = :c", [':c' => $svc]);
    $r = $sell($out, $massage, 1, 'room_charge', $inhouse);
    $rc2 = (int)$r['sale']['id'];
    $ic = $docOf($rc2, 'ic_invoice');
    check('room charge (re-invoice): an inter-company invoice to the property company, in the bill currency',
        $r['ok'] && $ic && (int)$ic['counterparty_company_id'] === $prop && $ic['currency'] === 'USD' && !$docOf($rc2));
    check('room charge (re-invoice): the property owes the spa company', ($b = acct_ic_balances([$prop]))[0]['debtor'] === $prop && abs($b[0]['cents'] - acct_cents($ic['total'])) === 0);
    $f = acct_folio($hold);
    check('room charge (re-invoice): on the folio as this property\'s revenue', (bool) array_filter($f['lines'], fn($l) => $l['category'] === 'spa' && !$l['is_disbursement']));
    check('pos void (re-invoice): voiding credits the inter-company invoice and clears the balance', pos_void_sale($rc2, 'mistake', $owner)['ok'] && acct_ic_balances([$prop, $svc]) === []);
    db_query("UPDATE companies SET room_charge_mode = 'on_behalf' WHERE id = :c", [':c' => $svc]);

    // Stock between companies.
    $store = inv_store_location_id();
    company_assign($svc, 'locations', [$store]);
    $propLoc = inv_property_location_id($venue);
    $item = inv_create_item(['name' => "ZZ Towel {$sfx}", 'tracking' => 'qty', 'replacement_value' => 500, 'currency' => 'KES']);
    $free = inv_create_item(['name' => "ZZ Pebble {$sfx}", 'tracking' => 'qty', 'currency' => 'KES']);
    inv_move(['item_id' => $item, 'qty' => 10, 'to' => $store, 'reason' => 'receive', 'user_id' => $owner]);
    inv_move(['item_id' => $free, 'qty' => 5, 'to' => $store, 'reason' => 'receive', 'user_id' => $owner]);
    inv_transfer($item, 3, $store, $propLoc, $owner);
    $st = db_query("SELECT * FROM acct_documents WHERE transfer_ref IS NOT NULL AND company_id = :c AND doc_type = 'ic_invoice' ORDER BY id DESC LIMIT 1", [':c' => $svc])->fetch();
    check('stock: a transfer to another company\'s property is invoiced at cost + VAT', $st && acct_cents($st['subtotal']) === 150000 && acct_cents($st['total']) === 174000
        && (int)$st['counterparty_company_id'] === $prop);
    check('stock: every move of the transfer shares its reference', (bool) db_query('SELECT 1 FROM inv_moves WHERE transfer_ref = :r AND item_id = :i', [':r' => $st['transfer_ref'], ':i' => $item])->fetchColumn());
    $kes = array_values(array_filter(acct_ic_balances([$prop]), fn($b) => $b['currency'] === 'KES'));
    check('stock: the property company owes the stock company', $kes && $kes[0]['debtor'] === $prop && $kes[0]['cents'] === 174000);
    $bal0 = inv_balance($free, $propLoc);
    check('stock: an item with no value can\'t cross companies (and nothing moved)', (function () use ($free, $store, $propLoc, $owner) {
        try { inv_transfer($free, 1, $store, $propLoc, $owner); return false; } catch (InvRefusal $e) { return str_contains($e->getMessage(), 'no replacement value'); }
    })() && inv_balance($free, $propLoc) === $bal0);
    // Restock to par: several lines → one invoice.
    $item2 = inv_create_item(['name' => "ZZ Plate {$sfx}", 'tracking' => 'qty', 'replacement_value' => 200, 'currency' => 'KES']);
    inv_move(['item_id' => $item2, 'qty' => 10, 'to' => $store, 'reason' => 'receive', 'user_id' => $owner]);
    inv_set_par($item, $propLoc, 5); inv_set_par($item2, $propLoc, 4);
    $before = (int) db_query("SELECT COUNT(*) FROM acct_documents WHERE doc_type = 'ic_invoice' AND company_id = :c", [':c' => $svc])->fetchColumn();
    inv_restock_to_par($propLoc, $owner, $store);
    $after = db_query("SELECT * FROM acct_documents WHERE doc_type = 'ic_invoice' AND company_id = :c ORDER BY id DESC LIMIT 1", [':c' => $svc])->fetch();
    check('stock: a restock of several items is ONE inter-company invoice', (int) db_query("SELECT COUNT(*) FROM acct_documents WHERE doc_type = 'ic_invoice' AND company_id = :c", [':c' => $svc])->fetchColumn() === $before + 1
        && (int) db_query('SELECT COUNT(*) FROM acct_document_lines WHERE document_id = :d', [':d' => $after['id']])->fetchColumn() === 2);
    // Same company: no invoice.
    $area = $ins("INSERT INTO inv_locations (kind, name, venue_id, parent_id) VALUES ('area', 'ZZ Kitchen', :v, :p)", [':v' => $venue, ':p' => $propLoc]);
    $cnt = (int) db_query("SELECT COUNT(*) FROM acct_documents WHERE doc_type = 'ic_invoice'")->fetchColumn();
    inv_transfer($item, 1, $propLoc, $area, $owner);
    check('stock: a move inside one company is not invoiced', (int) db_query("SELECT COUNT(*) FROM acct_documents WHERE doc_type = 'ic_invoice'")->fetchColumn() === $cnt);

    // Settlement.
    check('settle: more than is owed is refused', refused(fn() => acct_ic_settle($prop, $svc, '999999', $svcBank, 'TT1', $owner)));
    check('settle: the paid company\'s bank account is required', refused(fn() => acct_ic_settle($prop, $svc, '100', $propBank, 'TT1', $owner)));
    $owedBefore = array_values(array_filter(acct_ic_balances([$prop]), fn($b) => $b['currency'] === 'KES'))[0]['cents'];
    acct_ic_settle($prop, $svc, '1000', $svcBank, 'TT-2026-09', $owner);
    $owedAfter = array_values(array_filter(acct_ic_balances([$prop]), fn($b) => $b['currency'] === 'KES'))[0]['cents'];
    check('settle: a settlement reduces what is owed', $owedBefore - $owedAfter === 100000);
} catch (Throwable $e) {
    check('db block threw: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
}
try {
    if (db()->inTransaction() && isset($hold)) {
        [$ad] = company_account_clean(['label' => 'Prop cash USD', 'kind' => 'cash', 'currency' => 'USD']);
        $propCash = company_account_save($prop, null, $ad);
        // Security deposit → damages. Settle what is due first, so the deposit has nothing to pay yet.
        foreach (acct_hold_documents($hold) as $x) if ($x['balance_cents'] > 0) {
            acct_record_payment($hold, ['method' => 'bank', 'amount' => (string)($x['balance_cents'] / 100), 'account_id' => $propBank, 'reference' => 'TT0'], $owner);
        }
        $dep = acct_record_payment($hold, ['method' => 'cash', 'amount' => '200', 'account_id' => $propCash, 'is_security_deposit' => '1'], $owner);
        check('deposit: nothing to apply it to until an invoice is due', refused(fn() => acct_apply_deposit($dep, $owner)));
        $ins("INSERT INTO bill_items (hold_id, label, amount, currency) VALUES (:h, 'ZZ Broken lamp', 50, 'USD')", [':h' => $hold]);
        acct_issue_folio($hold, $owner);
        $used = acct_apply_deposit($dep, $owner);
        check('deposit: applied to the damage invoice', $used >= 5000 && acct_payment_available($dep) === 20000 - $used);
        check('deposit: the rest can still be refunded', !refused(fn() => acct_refund_payment($dep, (string)(acct_payment_available($dep) / 100), 'balance returned', $owner)));

        // Invoice at confirmation + credit on cancel.
        db_query("UPDATE companies SET invoice_timing = 'confirm' WHERE id = :c", [':c' => $prop]);
        $h2 = $ins("INSERT INTO holds (unit_id, check_in, check_out, guest_name, guest_email, status, expires_at)
                    VALUES (:u, :ci, :co, 'ZZ Future Guest', 'f@x.test', 'confirmed', NULL)", [':u' => $unit['id'], ':ci' => $d(20), ':co' => $d(23)]);
        db_query("INSERT INTO bookings (venue_id, source, guest_name, check_in, check_out, nights, gross_amount, currency, status, hold_id)
                  VALUES (:v, 'website', 'ZZ Future Guest', :ci, :co, 3, 450, 'USD', 'confirmed', :h)", [':v' => $venue, ':ci' => $d(20), ':co' => $d(23), ':h' => $h2]);
        $ins("INSERT INTO booking_addons (hold_id, kind, details, status) VALUES (:h, 'other', 'Unpriced cake', 'confirmed')", [':h' => $h2]);
        $msg = acct_hook_hold_confirmed($h2, $owner);
        check('confirm timing: the stay is invoiced at confirmation (an unpriced extra doesn\'t block it)', acct_stay_invoiced($h2) && str_contains($msg, 'Stay invoiced'));
        $msg = acct_hook_hold_cancelled($h2, $owner);
        check('cancel: the invoiced stay is credited', !acct_stay_invoiced($h2) && str_contains($msg, 'Stay credited'));
        db_query("UPDATE companies SET invoice_timing = 'checkout' WHERE id = :c", [':c' => $prop]);
        $h3 = $ins("INSERT INTO holds (unit_id, check_in, check_out, guest_name, guest_email, status, expires_at)
                    VALUES (:u, :ci, :co, 'ZZ Later', 'l@x.test', 'confirmed', NULL)", [':u' => $unit['id'], ':ci' => $d(30), ':co' => $d(31)]);
        db_query("INSERT INTO bookings (venue_id, source, guest_name, check_in, check_out, nights, gross_amount, currency, status, hold_id)
                  VALUES (:v, 'website', 'ZZ Later', :ci, :co, 1, 100, 'USD', 'confirmed', :h)", [':v' => $venue, ':ci' => $d(30), ':co' => $d(31), ':h' => $h3]);
        check('checkout timing: nothing is invoiced at confirmation', acct_hook_hold_confirmed($h3, $owner) === '' && !acct_stay_invoiced($h3));

        // Go-live guard.
        db_query('UPDATE pos_outlets SET is_active = TRUE WHERE id = :o', [':o' => $bare]);
        db_query('UPDATE companies SET accounting_starts_on = NULL WHERE id = :c', [':c' => $prop]);
        check('go-live: refused while an open till has no company', refused(fn() => acct_set_company_invoicing($prop, $d(-1), true)));
    }
} catch (Throwable $e) {
    check('db block (part 2) threw: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    if (db()->inTransaction()) db()->rollBack();
}
check('db: the test left nothing behind', (int) db_query("SELECT COUNT(*) FROM companies WHERE name LIKE 'ZZ %'")->fetchColumn() === 0);

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
