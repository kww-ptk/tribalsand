<?php
declare(strict_types=1);
/**
 * Accounting P2b — everything beyond the room folio: POS sale documents, the
 * inter-company ledger (room charges collected on behalf, re-invoices, stock
 * transfers, settlements), applying a security deposit, and the confirm / cancel
 * hooks for companies that invoice the stay at confirmation.
 * Loaded by includes/acct.php. Migration: add_acct_p2b.sql. Test: tests/acct_ops_logic.php.
 *
 * Rules that are load-bearing:
 *  - A POS sale commits its document INSIDE the sale's transaction; a refusal here
 *    refuses the sale (fail closed once a company is live). Nothing is invoiced twice:
 *    a room charge to a guest of the SAME company is invoiced on the folio only.
 *  - acct_ic_entries is append-only: "from owes to", a reversal or settlement is a
 *    negative row. Balances are always per currency.
 *  - Stock between companies is invoiced per transfer (one inv_moves.transfer_ref),
 *    at the moves' value snapshot, no markup; a line with no value is refused.
 *    Only 'transfer' and 'replaced' cross — handing equipment to staff never does.
 *  - Every guard is pre-migration-safe (acct_ops_supported()).
 */

/** True once add_acct_p2b.sql has run. */
function acct_ops_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    return $ok = acct_supported() && to_regclass_exists('acct_ic_entries');
}

/** Is a company invoicing on this Nairobi date? */
function acct_company_live_on(array|false $co, string $ymd): bool {
    return $co && companies_bool($co['is_active']) && !empty($co['accounting_starts_on']) && (string)$co['accounting_starts_on'] <= $ymd;
}

/** Does any company invoice yet? (Unassigned places then fail closed.) */
function acct_any_company_live(): bool {
    return acct_supported() && (bool) db_query('SELECT 1 FROM companies WHERE is_active AND accounting_starts_on IS NOT NULL LIMIT 1')->fetchColumn();
}

// ── Inter-company ledger ────────────────────────────────────────────────────

/** Append one inter-company row ("$from owes $to $cents"; negative reduces it). */
function acct_ic_record(int $from, int $to, int $cents, string $cur, string $kind, array $links = [], ?int $userId = null): void {
    if ($from === $to || $cents === 0) return;
    db_query('INSERT INTO acct_ic_entries (from_company_id, to_company_id, amount, currency, source_kind, document_id, document_line_id,
                                           payment_id, transfer_ref, note, created_by)
              VALUES (:f, :t, :a, :c, :k, :d, :dl, :p, :tr, :n, :u)',
        [':f' => $from, ':t' => $to, ':a' => acct_from_cents($cents), ':c' => strtoupper($cur), ':k' => $kind,
         ':d' => $links['document_id'] ?? null, ':dl' => $links['document_line_id'] ?? null, ':p' => $links['payment_id'] ?? null,
         ':tr' => $links['transfer_ref'] ?? null, ':n' => mb_substr((string)($links['note'] ?? ''), 0, 500), ':u' => $userId]);
}

/**
 * After a folio invoice is issued: every line collected on behalf of another
 * company records "this company owes it", and that company's own sale document for
 * the charge is settled by an inter-company receipt (§4.4 on_behalf).
 */
function acct_ic_after_folio_invoice(int $docId, array $co, ?int $userId): void {
    if (!acct_ops_supported()) return;
    $lines = db_query("SELECT l.*, b.pos_sale_id FROM acct_document_lines l LEFT JOIN bill_items b ON l.source_kind = 'bill_item' AND b.id = l.source_id
                        WHERE l.document_id = :d AND l.is_disbursement AND l.supplier_company_id IS NOT NULL ORDER BY l.id", [':d' => $docId])->fetchAll();
    $cur = (string) db_query('SELECT currency FROM acct_documents WHERE id = :d', [':d' => $docId])->fetchColumn();
    foreach ($lines as $l) {
        $supplier = (int)$l['supplier_company_id'];
        $payId = null;
        if (!empty($l['pos_sale_id'])) $payId = acct_ic_settle_sale_document((int)$l['pos_sale_id'], $supplier, (int)$co['id'], $userId);
        acct_ic_record((int)$co['id'], $supplier, acct_cents($l['line_total']), $cur, 'room_charge',
            ['document_id' => $docId, 'document_line_id' => (int)$l['id'], 'payment_id' => $payId, 'note' => $l['description']], $userId);
    }
}

/**
 * Settle the supplier's sale document for a room charge with an inter-company
 * receipt (the property now owes it instead of the guest). Returns the receipt id.
 */
function acct_ic_settle_sale_document(int $saleId, int $supplier, int $payer, ?int $userId): ?int {
    $doc = db_query("SELECT d.*, " . acct_doc_status_sql('d') . " FROM acct_documents d
                      WHERE d.pos_sale_id = :s AND d.company_id = :c AND d.doc_type = 'invoice'", [':s' => $saleId, ':c' => $supplier])->fetch();
    if (!$doc) return null;   // the supplier was not invoicing yet when the charge was made
    $doc = acct_doc_with_balance($doc);
    if ($doc['balance_cents'] <= 0) return null;
    $fx = acct_fx_rate((string) db_query('SELECT home_currency FROM companies WHERE id = :c', [':c' => $supplier])->fetchColumn(), (string)$doc['currency']) ?? 1.0;
    db_query("INSERT INTO acct_payments (company_id, account_id, kind, method, amount, currency, fx_to_home, reference, payer_name, counterparty_company_id, pos_sale_id, recorded_by)
              VALUES (:c, NULL, 'receipt', 'intercompany', :a, :cur, :fx, :ref, :payer, :cp, :s, :u)",
        [':c' => $supplier, ':a' => acct_from_cents($doc['balance_cents']), ':cur' => $doc['currency'], ':fx' => $fx,
         ':ref' => 'Room charge ' . $doc['number'], ':payer' => (string) db_query('SELECT name FROM companies WHERE id = :c', [':c' => $payer])->fetchColumn(),
         ':cp' => $payer, ':s' => $saleId, ':u' => $userId]);
    $pid = (int) db()->lastInsertId();
    db_query('INSERT INTO acct_allocations (payment_id, document_id, amount, pay_amount, rate) VALUES (:p, :d, :a, :a, 1)',
        [':p' => $pid, ':d' => $doc['id'], ':a' => acct_from_cents($doc['balance_cents'])]);
    return $pid;
}

/**
 * After a credit note: reverse the inter-company amounts behind the credited lines.
 * A disbursement line un-settles the supplier's sale document (its inter-company
 * receipt is released and refunded); an inter-company invoice reduces what the
 * counterparty owes.
 */
function acct_ic_after_credit(array $inv, array $creditedLines, int $cnId, ?int $userId): void {
    if (!acct_ops_supported()) return;
    if ($inv['doc_type'] === 'ic_invoice' && $inv['counterparty_company_id']) {
        $cents = array_sum(array_map(fn($l) => acct_cents($l['line_total']), $creditedLines));
        acct_ic_record((int)$inv['counterparty_company_id'], (int)$inv['company_id'], -$cents, (string)$inv['currency'],
            $inv['transfer_ref'] ? 'stock_transfer' : 'ic_invoice', ['document_id' => $cnId, 'transfer_ref' => $inv['transfer_ref']], $userId);
        return;
    }
    foreach ($creditedLines as $l) {
        if (!companies_bool($l['is_disbursement'])) continue;
        $e = db_query("SELECT * FROM acct_ic_entries WHERE document_line_id = :l AND source_kind = 'room_charge' AND amount > 0 ORDER BY id LIMIT 1",
            [':l' => (int)$l['id']])->fetch();
        if (!$e) continue;
        acct_ic_record((int)$e['from_company_id'], (int)$e['to_company_id'], -acct_cents($e['amount']), (string)$e['currency'], 'room_charge',
            ['document_id' => $cnId, 'document_line_id' => (int)$l['id'], 'note' => 'Reversed: ' . $l['description']], $userId);
        if ($e['payment_id']) {
            $p = db_query('SELECT * FROM acct_payments WHERE id = :p', [':p' => $e['payment_id']])->fetch();
            foreach (db_query('SELECT document_id, SUM(amount) AS amt, SUM(pay_amount) AS pay FROM acct_allocations WHERE payment_id = :p GROUP BY document_id HAVING SUM(amount) > 0',
                              [':p' => $e['payment_id']])->fetchAll() as $a) {
                db_query('INSERT INTO acct_allocations (payment_id, document_id, amount, pay_amount, rate) VALUES (:p, :d, :a, :pa, 1)',
                    [':p' => $e['payment_id'], ':d' => $a['document_id'], ':a' => -(float)$a['amt'], ':pa' => -(float)$a['pay']]);
            }
            db_query("INSERT INTO acct_payments (company_id, account_id, kind, refunds_payment_id, method, amount, currency, fx_to_home, reference, payer_name,
                                                 counterparty_company_id, pos_sale_id, reason, recorded_by)
                      VALUES (:c, NULL, 'refund', :r, 'intercompany', :a, :cur, :fx, :ref, :payer, :cp, :s, :why, :u)",
                [':c' => $p['company_id'], ':r' => $p['id'], ':a' => $p['amount'], ':cur' => $p['currency'], ':fx' => $p['fx_to_home'],
                 ':ref' => $p['reference'], ':payer' => $p['payer_name'], ':cp' => $p['counterparty_company_id'], ':s' => $p['pos_sale_id'],
                 ':why' => 'Folio line credited', ':u' => $userId]);
        }
    }
}

/**
 * Net inter-company balances per company pair and currency. Each row: the company
 * that owes (debtor), the one owed (creditor), currency, cents (> 0). Scoped to
 * $companyIds when given (null = all).
 */
function acct_ic_balances(?array $companyIds = null): array {
    if (!acct_ops_supported()) return [];
    $rows = db_query('SELECT from_company_id AS f, to_company_id AS t, currency, SUM(amount) AS amt FROM acct_ic_entries GROUP BY 1, 2, 3')->fetchAll();
    return acct_ic_net($rows, $companyIds);
}

/**
 * Net raw "f owes t amt" sums into one row per pair + currency. Pure.
 * $rows: [['f','t','currency','amt'], …]. Returns [['debtor','creditor','currency','cents'], …] sorted.
 */
function acct_ic_net(array $rows, ?array $companyIds = null): array {
    $net = [];
    foreach ($rows as $r) {
        [$a, $b] = [(int)$r['f'], (int)$r['t']];
        $key = min($a, $b) . '|' . max($a, $b) . '|' . $r['currency'];
        $net[$key] = ($net[$key] ?? 0) + ($a < $b ? 1 : -1) * acct_cents($r['amt']);   // + = the lower id owes the higher
    }
    $out = [];
    foreach ($net as $key => $cents) {
        if ($cents === 0) continue;
        [$lo, $hi, $cur] = explode('|', $key);
        [$debtor, $creditor] = $cents > 0 ? [(int)$lo, (int)$hi] : [(int)$hi, (int)$lo];
        if ($companyIds !== null && !in_array($debtor, $companyIds, true) && !in_array($creditor, $companyIds, true)) continue;
        $out[] = ['debtor' => $debtor, 'creditor' => $creditor, 'currency' => $cur, 'cents' => abs($cents)];
    }
    usort($out, fn($x, $y) => [$x['debtor'], $x['creditor'], $x['currency']] <=> [$y['debtor'], $y['creditor'], $y['currency']]);
    return $out;
}

/** Recent inter-company rows (newest first), optionally scoped to companies. */
function acct_ic_entries_list(?array $companyIds = null, int $limit = 200): array {
    if (!acct_ops_supported()) return [];
    $w = '';
    if ($companyIds !== null) {
        $ids = $companyIds ? implode(',', array_map('intval', $companyIds)) : '0';
        $w = "WHERE e.from_company_id IN ({$ids}) OR e.to_company_id IN ({$ids})";
    }
    return db_query("SELECT e.*, f.name AS from_name, t.name AS to_name, d.number AS doc_number
                       FROM acct_ic_entries e JOIN companies f ON f.id = e.from_company_id JOIN companies t ON t.id = e.to_company_id
                       LEFT JOIN acct_documents d ON d.id = e.document_id {$w} ORDER BY e.id DESC LIMIT " . max(1, $limit))->fetchAll();
}

/**
 * Record a settlement: $payer paid $payee $amount (bank transfer between the
 * companies). Reduces what $payer owes; the money lands in one of $payee's accounts.
 */
function acct_ic_settle(int $payer, int $payee, string $amountRaw, int $accountId, string $reference, ?int $userId): void {
    if ($payer === $payee) throw new AcctRefusal('Pick two different companies.');
    if (!is_numeric($amountRaw) || (float)$amountRaw <= 0 || (float)$amountRaw > ACCT_MAX_AMOUNT) throw new AcctRefusal('Enter the amount paid.');
    $reference = trim($reference);
    if ($reference === '' || mb_strlen($reference) > 80) throw new AcctRefusal('Add the bank reference (up to 80 characters).');
    $cents = acct_cents($amountRaw);
    company_tx(function () use ($payer, $payee, $cents, $accountId, $reference, $userId): void {
        $acc = db_query("SELECT * FROM company_accounts WHERE id = :a AND company_id = :c AND is_active AND kind = 'bank'", [':a' => $accountId, ':c' => $payee])->fetch();
        if (!$acc) throw new AcctRefusal('Pick the bank account of the company that was paid.');
        $cur = (string)$acc['currency'];
        $owed = 0;
        foreach (acct_ic_balances() as $b) if ($b['debtor'] === $payer && $b['creditor'] === $payee && $b['currency'] === $cur) $owed = $b['cents'];
        if ($cents > $owed) throw new AcctRefusal($owed > 0 ? 'That is more than is owed (' . acct_money($owed, $cur) . ').' : "Nothing is owed in {$cur} between these companies.");
        $home = (string) db_query('SELECT home_currency FROM companies WHERE id = :c', [':c' => $payee])->fetchColumn();
        db_query("INSERT INTO acct_payments (company_id, account_id, kind, method, amount, currency, fx_to_home, reference, payer_name, counterparty_company_id, recorded_by)
                  VALUES (:c, :a, 'receipt', 'intercompany', :amt, :cur, :fx, :ref, :payer, :cp, :u)",
            [':c' => $payee, ':a' => $accountId, ':amt' => acct_from_cents($cents), ':cur' => $cur, ':fx' => acct_fx_rate($home, $cur) ?? 1.0,
             ':ref' => $reference, ':payer' => (string) db_query('SELECT name FROM companies WHERE id = :c', [':c' => $payer])->fetchColumn(),
             ':cp' => $payer, ':u' => $userId]);
        acct_ic_record($payer, $payee, -$cents, $cur, 'settlement', ['payment_id' => (int) db()->lastInsertId(), 'note' => $reference], $userId);
    });
}

// ── POS sales ───────────────────────────────────────────────────────────────

/** POS payment method → accounting method (null = no automatic payment). */
function acct_pos_method(string $posMethod): ?string {
    return ['cash' => 'cash', 'card' => 'card', 'mobile_money' => 'mpesa'][$posMethod] ?? null;
}

/**
 * A POS sale as document lines — goods (per sale line), service charge, tip — with
 * the sale's VAT spread over the revenue lines so the lines add up to the sale's
 * own VAT and total to the cent. Pure.
 * $sale: subtotal, service_charge, vat_pct, vat_amount, vat_inclusive, tip_amount, total
 * $saleLines: [['id','name','qty','line_total','category'], …]
 */
function acct_pos_document_lines(array $sale, array $saleLines): array {
    $rate  = (float)($sale['vat_pct'] ?? 0);
    $incl  = companies_bool($sale['vat_inclusive'] ?? true);
    $vatT  = acct_cents($sale['vat_amount'] ?? 0);
    $base  = [];
    foreach ($saleLines as $l) {
        $base[] = ['description' => $l['name'] . ((int)$l['qty'] > 1 ? ' × ' . (int)$l['qty'] : ''), 'cents' => acct_cents($l['line_total']),
                   'category' => $l['category'] ?? 'other', 'source_kind' => 'pos_line', 'source_id' => (int)$l['id']];
    }
    if (acct_cents($sale['service_charge'] ?? 0) > 0) {
        $base[] = ['description' => 'Service charge', 'cents' => acct_cents($sale['service_charge']), 'category' => 'service', 'source_kind' => 'pos_sale', 'source_id' => null];
    }
    $out = []; $vatSum = 0; $big = null;
    foreach ($base as $i => $b) {
        [$net, $vat, $gross] = acct_vat_split($b['cents'], $vatT > 0 ? $rate : 0.0, $incl);
        $out[$i] = ['description' => mb_substr($b['description'], 0, 300), 'qty' => 1, 'category' => $b['category'], 'is_disbursement' => false,
                    'supplier_company_id' => null, 'source_kind' => $b['source_kind'], 'source_id' => $b['source_id'],
                    'net_cents' => $net, 'vat_cents' => $vat, 'gross_cents' => $gross, 'vat_rate' => $vatT > 0 ? $rate : 0.0,
                    'tax_band' => $vatT > 0 && $rate > 0 ? 'B' : 'D'];
        $vatSum += $vat;
        if ($big === null || $b['cents'] > $base[$big]['cents']) $big = $i;
    }
    if ($big !== null && $vatSum !== $vatT) {   // rounding: the largest line absorbs the last cent(s)
        $d = $vatT - $vatSum;
        $out[$big]['vat_cents'] += $d;
        if ($incl) $out[$big]['net_cents'] -= $d; else $out[$big]['gross_cents'] += $d;
    }
    $tip = acct_cents($sale['tip_amount'] ?? 0);
    if ($tip > 0) {
        $out[] = ['description' => 'Tip', 'qty' => 1, 'category' => 'tip', 'is_disbursement' => false, 'supplier_company_id' => null,
                  'source_kind' => 'pos_sale', 'source_id' => null, 'net_cents' => $tip, 'vat_cents' => 0, 'gross_cents' => $tip, 'vat_rate' => 0.0, 'tax_band' => 'D'];
    }
    return array_values($out);
}

/**
 * Issue the document for a just-completed POS sale (called inside the sale's
 * transaction). Returns the document id, or null when nothing is due:
 *   - the outlet's company is not invoicing yet;
 *   - a room charge to a guest of the SAME company (it goes on the folio).
 * A walk-in / direct payment → a tax invoice paid at once into the company's
 * matching account. A room charge to another company's guest → 'on_behalf': an
 * invoice to the guest, settled later by the property; 'reinvoice': an
 * inter-company invoice to the property company. Throws AcctRefusal to refuse the
 * sale (an outlet with no company while invoicing is live anywhere).
 */
function acct_pos_sale_issue(int $saleId, ?int $userId): ?int {
    if (!acct_ops_supported()) return null;
    $s = db_query('SELECT s.*, o.kind AS outlet_kind, o.name AS outlet_name FROM pos_sales s JOIN pos_outlets o ON o.id = s.outlet_id WHERE s.id = :s', [':s' => $saleId])->fetch();
    if (!$s) return null;
    $coId = company_for_outlet((int)$s['outlet_id']);
    if (!$coId) {
        if (acct_any_company_live()) throw new AcctRefusal("{$s['outlet_name']} has no company yet — ask the owner to assign it in Accounting → Companies before selling.");
        return null;
    }
    $co = company_fetch($coId);
    $today = date('Y-m-d');
    $cat = acct_category_for_outlet((string)$s['outlet_kind']);
    $saleLines = array_map(fn($l) => $l + ['category' => acct_category_for_outlet((string)$l['owning_kind'])],
        db_query('SELECT l.id, l.name, l.qty, l.line_total, l.owning_outlet_id, o.kind AS owning_kind
                    FROM pos_sale_lines l JOIN pos_outlets o ON o.id = l.owning_outlet_id WHERE l.sale_id = :s ORDER BY l.id', [':s' => $saleId])->fetchAll());
    $lines = acct_pos_document_lines($s, $saleLines);

    if ($s['payment_method'] !== 'room_charge') return acct_pos_sale_issue_direct($s, $co, $saleLines, $lines, $userId);
    if (!acct_company_live_on($co, $today)) return null;

    // Room charges stay with the SELLING outlet's company: one folio line can't be split
    // between companies. (A cross-sold item from another company's outlet is rare here.)
    if ($s['payment_method'] === 'room_charge') {
        $propCo = company_for_venue($s['guest_venue_id'] ? (int)$s['guest_venue_id'] : null)
               ?? company_for_venue($s['hold_id'] ? (int) db_query('SELECT r.venue_id FROM holds h JOIN units u ON u.id = h.unit_id JOIN rooms r ON r.id = ' . hold_room_id_sql('h', 'u') . ' WHERE h.id = :h', [':h' => $s['hold_id']])->fetchColumn() : null);
        if ($propCo === $coId) return null;   // same company: invoiced once, on the folio
        if (!$propCo) {
            if (acct_any_company_live()) throw new AcctRefusal("The guest's property has no company yet — ask the owner to assign it before room-charging.");
            return null;
        }
        if (($co['room_charge_mode'] ?? 'on_behalf') === 'reinvoice') {
            // To the property company, in the BILL currency at the sale's frozen rate — both sides agree to the cent.
            $billCur = strtoupper((string)($s['bill_currency'] ?: $s['currency']));
            $billC   = acct_cents($s['bill_amount'] ?? $s['total']);
            $fx      = (float)($s['fx_rate'] ?? 0);
            $conv    = fn(float $v): int => ($billCur === strtoupper((string)$s['currency']) || $fx <= 0) ? acct_cents($v) : (int) round(acct_cents($v) / $fx, 0, PHP_ROUND_HALF_UP);
            $tip   = min($billC, $conv((float)$s['tip_amount']));
            $vat   = min($billC - $tip, $conv((float)$s['vat_amount']));
            $ic = [acct_build_line(['description' => "{$s['outlet_name']} — room charge {$s['reference']} ({$s['customer_name']})", 'cents' => $billC - $tip,
                                    'category' => $cat, 'source_kind' => 'pos_sale', 'source_id' => $saleId,
                                    'vat_snapshot' => ['rate' => (float)$s['vat_pct'], 'vat_cents' => $vat]], $co)];
            if ($tip > 0) $ic[] = acct_build_line(['description' => 'Tip — ' . $s['outlet_name'], 'cents' => $tip, 'category' => 'tip', 'source_kind' => 'pos_sale', 'source_id' => $saleId], $co);
            $prop = company_fetch($propCo);
            $docId = acct_insert_document($co, 'ic_invoice', $billCur, $ic, ['customer_kind' => 'company', 'customer_name' => $prop['legal_name'] ?: $prop['name'],
                'customer_pin' => $prop['kra_pin'], 'counterparty_company_id' => $propCo, 'pos_sale_id' => $saleId, 'issued_by' => $userId]);
            acct_ic_record($propCo, $coId, acct_totals($ic)['gross'], $billCur, 'ic_invoice', ['document_id' => $docId, 'note' => $s['reference']], $userId);
            return $docId;
        }
        return acct_insert_document($co, 'invoice', strtoupper((string)$s['currency']), $lines,
            ['customer_kind' => 'guest', 'customer_name' => $s['customer_name'], 'hold_id' => null, 'pos_sale_id' => $saleId, 'issued_by' => $userId]);
    }

    return null;   // (unreachable: direct sales return above)
}

/**
 * A directly paid POS sale (cash / card / M-Pesa / other). Each item belongs to the
 * company of the outlet that OWNS it (a cross-sold item stays its own company's
 * revenue); service charge and tip belong to the selling outlet's company. One
 * invoice per company that is invoicing, each paid at once into that company's
 * matching account. An item whose company can't be resolved refuses the sale once
 * any company invoices. Returns the first document id (or null).
 */
function acct_pos_sale_issue_direct(array $s, array $sellerCo, array $saleLines, array $lines, ?int $userId): ?int {
    $today = date('Y-m-d');
    $ownerOf = [];
    foreach ($saleLines as $l) $ownerOf[(int)$l['id']] = company_for_outlet((int)$l['owning_outlet_id']);
    $groups = [];
    foreach ($lines as $l) {
        $cid = $l['source_kind'] === 'pos_line' ? ($ownerOf[(int)$l['source_id']] ?? null) : (int)$sellerCo['id'];
        if (!$cid) {
            if (acct_any_company_live()) throw new AcctRefusal("An item on this sale belongs to an outlet with no company — ask the owner to assign it before selling.");
            continue;
        }
        $groups[$cid][] = $l;
    }
    ksort($groups);
    $first = null;
    $cur = strtoupper((string)$s['currency']);
    $method = acct_pos_method((string)$s['payment_method']);
    foreach ($groups as $cid => $ls) {
        $co = (int)$cid === (int)$sellerCo['id'] ? $sellerCo : company_fetch((int)$cid);
        if (!acct_company_live_on($co, $today)) continue;   // that company is not invoicing yet
        $docId = acct_insert_document($co, 'invoice', $cur, $ls, [
            'customer_kind' => $s['customer_type'] === 'inhouse' ? 'guest' : 'walkin',
            'customer_name' => $s['customer_name'] ?: 'Walk-in', 'pos_sale_id' => (int)$s['id'], 'issued_by' => $userId]);
        $first ??= $docId;
        if ($method === null) continue;   // "other": left for staff to reconcile
        $acc = null;
        foreach (company_accounts((int)$cid, true) as $a) {
            if ($a['currency'] === $cur && in_array($a['kind'], ACCT_METHOD_ACCOUNTS[$method], true) && ($acc === null || companies_bool($a['is_default']))) $acc = $a;
        }
        if (!$acc) continue;   // no matching account: the invoice stays due and shows on the list
        $total = acct_totals($ls)['gross'];
        db_query("INSERT INTO acct_payments (company_id, account_id, kind, method, amount, currency, fx_to_home, reference, payer_name, pos_sale_id, recorded_by)
                  VALUES (:c, :a, 'receipt', :m, :amt, :cur, :fx, :ref, :payer, :s, :u)",
            [':c' => $cid, ':a' => $acc['id'], ':m' => $method, ':amt' => acct_from_cents($total), ':cur' => $cur,
             ':fx' => acct_fx_rate((string)$co['home_currency'], $cur) ?? 1.0, ':ref' => trim((string)($s['payment_ref'] ?? '')) ?: $s['reference'],
             ':payer' => mb_substr((string)$s['customer_name'], 0, 200), ':s' => (int)$s['id'], ':u' => $userId]);
        db_query('INSERT INTO acct_allocations (payment_id, document_id, amount, pay_amount, rate) VALUES (:p, :d, :a, :a, 1)',
            [':p' => (int) db()->lastInsertId(), ':d' => $docId, ':a' => acct_from_cents($total)]);
    }
    return $first;
}

/**
 * A POS sale was voided: credit its document(s) in full and refund what was paid at
 * the till. Called inside the void's transaction.
 */
function acct_pos_sale_void(int $saleId, string $reason, ?int $userId): void {
    if (!acct_ops_supported()) return;
    foreach (db_query("SELECT d.*, " . acct_doc_status_sql('d') . " FROM acct_documents d
                        WHERE d.pos_sale_id = :s AND d.doc_type IN ('invoice','ic_invoice') ORDER BY d.id", [':s' => $saleId])->fetchAll() as $d) {
        if (acct_doc_with_balance($d)['fully_credited']) continue;
        acct_credit_document((int)$d['id'], 'Sale voided: ' . $reason, $userId);
    }
    foreach (db_query("SELECT p.*, COALESCE((SELECT SUM(a.pay_amount) FROM acct_allocations a WHERE a.payment_id = p.id), 0) AS allocated,
                              COALESCE((SELECT SUM(r.amount) FROM acct_payments r WHERE r.refunds_payment_id = p.id), 0) AS refunded
                         FROM acct_payments p WHERE p.pos_sale_id = :s AND p.kind = 'receipt' AND p.method <> 'intercompany'", [':s' => $saleId])->fetchAll() as $p) {
        $left = acct_cents($p['amount']) - acct_cents($p['allocated']) - acct_cents($p['refunded']);
        if ($left > 0) acct_refund_payment((int)$p['id'], (string)acct_from_cents($left), 'Sale voided: ' . $reason, $userId);
    }
}

// ── Stock between companies ─────────────────────────────────────────────────

/**
 * After a stock action: invoice every move of one transfer that crossed from one
 * company to another — one inter-company invoice per (sender, receiver, currency),
 * at the move's value snapshot, VAT added per the sender. Called inside the
 * action's transaction; throws AcctRefusal to refuse it (an unowned end while
 * invoicing is live, or a line with no value).
 */
function acct_stock_transfer_issue(string $ref, ?int $userId): array {
    if (!acct_ops_supported() || $ref === '') return [];
    $moves = db_query("SELECT m.*, i.name AS item_name FROM inv_moves m JOIN inv_items i ON i.id = m.item_id
                        WHERE m.transfer_ref = :r AND m.reason IN ('transfer','replaced') AND m.from_location_id IS NOT NULL AND m.to_location_id IS NOT NULL
                        ORDER BY m.id", [':r' => $ref])->fetchAll();
    $groups = [];
    $live = null;
    foreach ($moves as $m) {
        $from = company_for_location((int)$m['from_location_id']);
        $to   = company_for_location((int)$m['to_location_id']);
        if ($from !== null && $from === $to) continue;
        if ($from === null || $to === null) {
            $live ??= acct_any_company_live();
            if ($live) {
                $name = (string) db_query('SELECT name FROM inv_locations WHERE id = :l', [':l' => $from === null ? $m['from_location_id'] : $m['to_location_id']])->fetchColumn();
                throw new AcctRefusal("{$name} has no company yet — ask the owner to assign it before moving stock there.");
            }
            continue;
        }
        $groups["{$from}|{$to}|{$m['currency']}"][] = $m;
    }
    $ids = [];
    foreach ($groups as $key => $ms) {
        [$from, $to, $cur] = explode('|', $key);
        $sender = company_fetch((int)$from);
        if (!acct_company_live_on($sender, date('Y-m-d'))) continue;   // the sender is not invoicing yet
        $lines = [];
        foreach ($ms as $m) {
            if ($m['value'] === null || (float)$m['value'] <= 0) {
                throw new AcctRefusal("{$m['item_name']} has no replacement value — set one before moving it to another company (the transfer is invoiced at that value).");
            }
            // Cost snapshot, no markup; VAT on top for a VAT-registered sender.
            $lines[] = acct_build_line(['description' => "{$m['item_name']} × {$m['qty']}", 'cents' => acct_cents($m['value']), 'category' => 'retail',
                                        'source_kind' => 'inv_move', 'source_id' => (int)$m['id']], ['prices_include_vat' => false] + $sender);
        }
        $recv = company_fetch((int)$to);
        $docId = acct_insert_document($sender, 'ic_invoice', $cur, $lines, ['customer_kind' => 'company', 'customer_name' => $recv['legal_name'] ?: $recv['name'],
            'customer_pin' => $recv['kra_pin'], 'counterparty_company_id' => (int)$to, 'transfer_ref' => $ref, 'issued_by' => $userId]);
        acct_ic_record((int)$to, (int)$from, acct_totals($lines)['gross'], $cur, 'stock_transfer', ['document_id' => $docId, 'transfer_ref' => $ref], $userId);
        $ids[] = $docId;
    }
    return $ids;
}

// ── Security deposit → damages ──────────────────────────────────────────────

/**
 * Use a held security deposit to pay the booking's open invoices (e.g. a damage
 * charge that was added and invoiced). Returns the amount applied, in the
 * deposit's currency (cents).
 */
function acct_apply_deposit(int $paymentId, ?int $userId): int {
    return company_tx(function () use ($paymentId): int {
        $p = db_query("SELECT * FROM acct_payments WHERE id = :p AND kind = 'receipt' AND is_security_deposit FOR UPDATE", [':p' => $paymentId])->fetch();
        if (!$p) throw new AcctRefusal('That is not a security deposit.');
        $avail = 0;
        foreach (acct_hold_payments((int)$p['hold_id']) as $r) if ((int)$r['id'] === $paymentId) $avail = $r['available_cents'];
        if ($avail <= 0) throw new AcctRefusal('Nothing is left of this deposit.');
        $docs = [];
        foreach (acct_hold_documents((int)$p['hold_id']) as $d) if ($d['balance_cents'] > 0) $docs[] = ['id' => (int)$d['id'], 'currency' => $d['currency'], 'balance' => $d['balance_cents']];
        if (!$docs) throw new AcctRefusal('Add the damage charge and issue its invoice first — the deposit pays an invoice.');
        $plan = acct_allocation_plan($docs, [['id' => $paymentId, 'currency' => $p['currency'], 'available' => $avail]], fn($f, $t) => acct_fx_rate($f, $t));
        if (!$plan) throw new AcctRefusal('No exchange rate to apply this deposit to the invoice currency.');
        $used = 0;
        foreach ($plan as $a) {
            db_query('INSERT INTO acct_allocations (payment_id, document_id, amount, pay_amount, rate) VALUES (:p, :d, :a, :pa, :r)',
                [':p' => $paymentId, ':d' => $a['document_id'], ':a' => acct_from_cents($a['amount']), ':pa' => acct_from_cents($a['pay_amount']), ':r' => $a['rate']]);
            $used += $a['pay_amount'];
        }
        return $used;
    });
}

// ── Booking confirm / cancel hooks ──────────────────────────────────────────

/**
 * A booking was confirmed. A company that invoices at CONFIRMATION issues the stay
 * invoice now (extras still go on at check-out). Best-effort: a failure never
 * blocks the confirmation — the stay simply stays open on the folio. Returns a
 * message for staff ('' when nothing happened).
 */
function acct_hook_hold_confirmed(int $holdId, ?int $userId): string {
    try {
        if (!acct_supported()) return '';
        $ctx = acct_hold_context($holdId);
        if (!$ctx['live'] || ($ctx['company']['invoice_timing'] ?? 'checkout') !== 'confirm') return '';
        $ids = acct_issue_folio($holdId, $userId, '', ['stay']);
        $nums = db_query('SELECT number FROM acct_documents WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')')->fetchAll(PDO::FETCH_COLUMN);
        return ' Stay invoiced: ' . implode(', ', $nums) . '.';
    } catch (Throwable $e) {
        error_log('[acct] confirm hook for hold ' . $holdId . ': ' . $e->getMessage());
        return $e instanceof AcctRefusal ? ' Stay not invoiced yet: ' . $e->getMessage() : '';
    }
}

/**
 * A booking was cancelled. If its stay is on an issued invoice, credit that line
 * (a cancellation fee is added as a charge and invoiced separately). Best-effort.
 */
function acct_hook_hold_cancelled(int $holdId, ?int $userId): string {
    try {
        if (!acct_supported()) return '';
        $line = db_query("SELECT l.id, l.document_id FROM acct_document_lines l JOIN acct_documents d ON d.id = l.document_id
                           WHERE l.source_kind = 'stay' AND l.source_id = :h AND d.doc_type = 'invoice'
                             AND NOT EXISTS (SELECT 1 FROM acct_document_lines c WHERE c.credits_line_id = l.id) LIMIT 1", [':h' => $holdId])->fetch();
        if (!$line) return '';
        $cn = acct_credit_document((int)$line['document_id'], 'Booking cancelled', $userId, [(int)$line['id']]);
        return ' Stay credited: ' . db_query('SELECT number FROM acct_documents WHERE id = :d', [':d' => $cn])->fetchColumn() . '.';
    } catch (Throwable $e) {
        error_log('[acct] cancel hook for hold ' . $holdId . ': ' . $e->getMessage());
        return '';
    }
}
