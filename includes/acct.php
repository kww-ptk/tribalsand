<?php
declare(strict_types=1);
/**
 * Accounting P2a — the room folio as a sales sub-ledger: tax invoices, credit
 * notes, payments and their allocation. Spec: docs/superpowers/specs/
 * 2026-09-27-accounting-layer-design.md §4 (+ corrections §12).
 * Migration: db/migrations/add_acct_documents.sql. Test: php tests/acct_logic.php.
 *
 * Rules that are load-bearing:
 *  - Amounts come from the existing single sources — never re-quoted: the stay is
 *    bookings.gross_amount (frozen at confirm), extras are the stored request / bill
 *    prices, a POS room charge is its stored bill line and the sale's VAT snapshot.
 *  - Issued documents are immutable. A correction is a (full) credit note, which
 *    releases the lines back onto the open folio and the payments back to
 *    "unapplied" — by appending rows, never editing or deleting any.
 *  - An invoiced bill line is LOCKED (document_id): it cannot be deleted, re-priced,
 *    un-confirmed or voided at the POS until its invoice is credited.
 *  - One currency per document; money is never summed across currencies.
 *  - Everything is inert until the property's company has an "invoicing starts on"
 *    date (companies.accounting_starts_on). A folio belongs to accounting when its
 *    CHECK-OUT is on/after that date (correction #9).
 *  - Integer-cent arithmetic; NUMERIC(12,2) storage.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/companies.php';

const ACCT_VAT_STANDARD = 16.0;   // KRA band B
const ACCT_MAX_AMOUNT   = 99999999.99;
const ACCT_PAY_METHODS  = ['cash' => 'Cash', 'card' => 'Card', 'mpesa' => 'M-Pesa', 'bank' => 'Bank transfer', 'ota_payout' => 'OTA payout'];
const ACCT_CATEGORY_LABELS = [
    'room' => 'Accommodation', 'fnb' => 'Food & drink', 'retail' => 'Shop', 'activity' => 'Activities', 'spa' => 'Spa',
    'transfer' => 'Transfers', 'service' => 'Services', 'other' => 'Other', 'tip' => 'Tips (for staff)',
    'disbursement' => 'Paid on behalf',
];

/** Refusal the caller shows to staff. */
class AcctRefusal extends RuntimeException {}

// ── Guards ──────────────────────────────────────────────────────────────────

/**
 * True once BOTH invoicing migrations have run (add_acct_documents.sql, then
 * add_acct_p2b.sql). The document writer uses columns from each, so with only the
 * first applied everything stays inert rather than failing half-way through an issue.
 */
function acct_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    return $ok = companies_supported() && to_regclass_exists('acct_documents') && to_regclass_exists('acct_ic_entries');
}

/** Who may record payments and issue invoices: the guest-facing tier (require_frontdesk()'s audience). */
function acct_can_take_payments(): bool {
    if (is_owner() || is_manager() || is_reception()) return true;
    $job = admin_job();
    return is_staff() && !job_is_ops($job) && !job_is_pos($job) && $job !== 'security';
}

/** Credit notes and refunds: the owner, or a manager of that property. */
function acct_can_reverse(?int $venueId): bool {
    if (is_owner()) return true;
    if (!is_manager() || !$venueId) return false;
    return in_array($venueId, admin_venue_ids() ?? [], true);
}

// ── Pure helpers ────────────────────────────────────────────────────────────

function acct_cents(mixed $amount): int { return (int) round(((float)$amount) * 100, 0, PHP_ROUND_HALF_UP); }
function acct_from_cents(int $cents): float { return $cents / 100; }

/** "USD 1,234.50" / "KES 12,000.00" — documents always show 2 decimals. */
function acct_money(int $cents, string $cur): string {
    return strtoupper($cur) . ' ' . number_format($cents / 100, 2);
}

/** Request kind → revenue category. */
function acct_category_for_addon(string $kind): string {
    return match ($kind) {
        'tour'       => 'activity',
        'transfer'   => 'transfer',
        'restaurant' => 'fnb',
        'housekeeping', 'amenities', 'laundry', 'maintenance', 'itinerary' => 'service',
        default      => 'other',
    };
}

/** POS outlet kind → revenue category. */
function acct_category_for_outlet(string $kind): string {
    return match ($kind) {
        'experiences', 'kite' => 'activity',
        'shop'      => 'retail',
        'salon_spa' => 'spa',
        default     => 'other',
    };
}

/**
 * Split an amount into net + VAT. $inclusive: $cents is what the customer pays and
 * already contains VAT; otherwise $cents is the net and VAT is added on top.
 * Returns [net, vat, gross] in cents. Pure.
 */
function acct_vat_split(int $cents, float $rate, bool $inclusive): array {
    if ($rate <= 0) return [$cents, 0, $cents];
    if ($inclusive) {
        $vat = (int) round($cents * $rate / (100 + $rate), 0, PHP_ROUND_HALF_UP);
        return [$cents - $vat, $vat, $cents];
    }
    $vat = (int) round($cents * $rate / 100, 0, PHP_ROUND_HALF_UP);
    return [$cents, $vat, $cents + $vat];
}

/**
 * Turn a folio source into a document line. Pure.
 * $src: description, cents, category, source_kind, source_id, [is_disbursement,
 *       supplier_company_id, vat_snapshot => ['rate' => float, 'vat_cents' => int]]
 * $company: vat_registered, prices_include_vat.
 *   - A disbursement or a tip is never revenue: band D, no VAT.
 *   - A POS line keeps the sale's own VAT snapshot (the guest already paid it).
 *   - Otherwise: VAT-registered → band B 16% (inclusive or on top per the company);
 *     not registered → band D, no VAT.
 */
function acct_build_line(array $src, array $company): array {
    $cents = (int)$src['cents'];
    $cat   = (string)($src['category'] ?? 'other');
    $disb  = !empty($src['is_disbursement']);
    $line  = [
        'description' => mb_substr((string)$src['description'], 0, 300), 'qty' => 1,
        'category' => $disb ? 'disbursement' : $cat, 'is_disbursement' => $disb,
        'supplier_company_id' => $src['supplier_company_id'] ?? null,
        'source_kind' => (string)($src['source_kind'] ?? ''), 'source_id' => $src['source_id'] ?? null,
    ];
    if ($disb || $cat === 'tip') {
        [$net, $vat, $gross, $rate, $band] = [$cents, 0, $cents, 0.0, 'D'];
    } elseif (isset($src['vat_snapshot'])) {
        $rate = (float)$src['vat_snapshot']['rate'];
        $vat  = max(0, min($cents, (int)$src['vat_snapshot']['vat_cents']));
        [$net, $gross, $band] = [$cents - $vat, $cents, $rate > 0 ? 'B' : 'D'];
    } elseif (companies_bool($company['vat_registered'] ?? false)) {
        $rate = ACCT_VAT_STANDARD;
        [$net, $vat, $gross] = acct_vat_split($cents, $rate, companies_bool($company['prices_include_vat'] ?? true));
        $band = 'B';
    } else {
        [$net, $vat, $gross, $rate, $band] = [$cents, 0, $cents, 0.0, 'D'];
    }
    return $line + ['net_cents' => $net, 'vat_cents' => $vat, 'gross_cents' => $gross, 'vat_rate' => $rate, 'tax_band' => $band];
}

/** Postgres/PHP boolean → bool. */
function companies_bool(mixed $v): bool { return $v === true || $v === 't' || $v === 1 || $v === '1' || $v === 'true'; }

/** Totals of built lines, in cents. Pure. */
function acct_totals(array $lines): array {
    $t = ['net' => 0, 'vat' => 0, 'gross' => 0];
    foreach ($lines as $l) { $t['net'] += $l['net_cents']; $t['vat'] += $l['vat_cents']; $t['gross'] += $l['gross_cents']; }
    return $t;
}

/**
 * Plan how unapplied payments settle open documents — oldest payment into oldest
 * document. Pure.
 * $docs:     [['id', 'currency', 'balance'(cents)], …] oldest first
 * $payments: [['id', 'currency', 'available'(cents)], …] oldest first
 * $rate:     fn(payCur, docCur): ?float — payment-currency units per 1 document unit
 *            (null = no rate: that pair is skipped, never guessed)
 * Returns [['payment_id','document_id','amount'(doc cents),'pay_amount'(pay cents),'rate'], …].
 */
function acct_allocation_plan(array $docs, array $payments, callable $rate): array {
    $plan = [];
    foreach ($payments as &$p) {
        foreach ($docs as &$d) {
            if ($p['available'] <= 0) break;
            if ($d['balance'] <= 0) continue;
            $r = $p['currency'] === $d['currency'] ? 1.0 : $rate($p['currency'], $d['currency']);
            if ($r === null || $r <= 0) continue;
            $needPay = (int) round($d['balance'] * $r, 0, PHP_ROUND_HALF_UP);   // payment cents that clear the balance
            if ($p['available'] >= $needPay) {
                [$docAmt, $payAmt] = [$d['balance'], $needPay];
            } else {
                $payAmt = $p['available'];
                $docAmt = min($d['balance'], (int) round($payAmt / $r, 0, PHP_ROUND_HALF_UP));
            }
            if ($docAmt <= 0 || $payAmt <= 0) continue;
            $plan[] = ['payment_id' => $p['id'], 'document_id' => $d['id'], 'amount' => $docAmt, 'pay_amount' => $payAmt, 'rate' => $r];
            $p['available'] -= $payAmt;
            $d['balance']   -= $docAmt;
        }
        unset($d);
    }
    unset($p);
    return $plan;
}

/** Which account kinds a payment method can land in. */
const ACCT_METHOD_ACCOUNTS = [
    'cash'       => ['cash', 'bank'],
    'card'       => ['card_merchant', 'bank'],
    'mpesa'      => ['mpesa_till', 'mpesa_paybill'],
    'bank'       => ['bank'],
    'ota_payout' => ['bank'],
];

/** Can money paid this way land in this kind of account? Pure. */
function acct_method_fits_account(string $method, string $accountKind): bool {
    return in_array($accountKind, ACCT_METHOD_ACCOUNTS[$method] ?? [], true);
}

/** Site FX: units of $from per 1 $to (null when a rate is missing). */
function acct_fx_rate(string $from, string $to): ?float {
    $from = strtoupper($from); $to = strtoupper($to);
    if ($from === $to) return 1.0;
    $rates = fx_rates()['rates'] ?? [];
    $rf = (float)($rates[$from] ?? 0); $rt = (float)($rates[$to] ?? 0);
    if ($rf <= 0 || $rt <= 0) return null;
    return round($rf / $rt, 6);
}

// ── Folio context ───────────────────────────────────────────────────────────

/**
 * Everything the folio needs to know about a hold: the hold, its property, the
 * owning company and whether accounting is live for it. 'reason' explains why not.
 */
function acct_hold_context(int $holdId): array {
    $ctx = ['hold' => null, 'venue_id' => null, 'company' => null, 'live' => false, 'reason' => ''];
    if (!acct_supported()) { $ctx['reason'] = 'Invoicing is not set up yet (run add_acct_documents.sql, then add_acct_p2b.sql).'; return $ctx; }
    $agent = holds_agent_supported() && to_regclass_exists('travel_agents');
    $h = db_query(
        "SELECT h.*, r.name AS room_name, r.venue_id, v.name AS venue_name"
        . ($agent ? ", ta.name AS agent_name, ta.agency AS agent_agency" : ", NULL AS agent_name, NULL AS agent_agency") . "
           FROM holds h JOIN units u ON u.id = h.unit_id JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u') . "
           LEFT JOIN venues v ON v.id = r.venue_id"
        . ($agent ? " LEFT JOIN travel_agents ta ON ta.id = h.agent_id" : "") . "
          WHERE h.id = :id", [':id' => $holdId])->fetch();
    if (!$h) { $ctx['reason'] = 'Booking not found.'; return $ctx; }
    $ctx['hold'] = $h;
    $ctx['venue_id'] = $h['venue_id'] ? (int)$h['venue_id'] : null;
    $coId = company_for_venue($ctx['venue_id']);
    $co   = $coId ? company_fetch($coId) : false;
    if (!$co) { $ctx['reason'] = ($h['venue_name'] ?: 'This property') . ' has no company yet — the owner sets it in Accounting → Companies.'; return $ctx; }
    $ctx['company'] = $co;
    if (!companies_bool($co['is_active'])) { $ctx['reason'] = "{$co['name']} is switched off."; return $ctx; }
    if (empty($co['accounting_starts_on'])) { $ctx['reason'] = "Invoicing is not live for {$co['name']} yet — the owner sets the start date in Accounting → Companies."; return $ctx; }
    if ((string)$h['check_out'] < (string)$co['accounting_starts_on']) {
        $ctx['reason'] = 'This stay checks out before invoicing started for ' . $co['name'] . ' (' . date('j M Y', strtotime((string)$co['accounting_starts_on'])) . '), so it stays with the old process.';
        return $ctx;
    }
    $ctx['live'] = true;
    return $ctx;
}

/** Is the stay of this hold already on an invoice that has not been credited? */
function acct_stay_invoiced(int $holdId): bool {
    return (bool) db_query(
        "SELECT 1 FROM acct_document_lines l JOIN acct_documents d ON d.id = l.document_id
          WHERE l.source_kind = 'stay' AND l.source_id = :h AND d.doc_type = 'invoice'
            AND NOT EXISTS (SELECT 1 FROM acct_document_lines c WHERE c.credits_line_id = l.id) LIMIT 1",
        [':h' => $holdId])->fetchColumn();
}

/**
 * The folio's charges that are not yet on an invoice, as sources (see
 * acct_build_line()), plus anything that blocks issuing. Reads only.
 * Returns ['sources' => [...], 'blockers' => [msg…], 'notes' => [msg…]].
 */
function acct_open_sources(array $ctx): array {
    $h = $ctx['hold']; $co = $ctx['company']; $holdId = (int)$h['id'];
    $site = strtoupper(setting('site_currency', 'USD'));
    $out = ['sources' => [], 'blockers' => [], 'notes' => [], 'stay_blocker' => null];

    // The stay — the frozen confirm-time figure, never re-quoted.
    if (!acct_stay_invoiced($holdId)) {
        $b = bookings_row_for_hold($holdId);
        if ($h['status'] !== 'confirmed' || !$b || $b['status'] !== 'confirmed') {
            $out['notes'][] = 'The stay is added once the booking is confirmed.';
        } elseif ((float)$b['gross_amount'] <= 0) {
            $out['blockers'][] = $out['stay_blocker'] = 'The stay has no price on record — check the room rate, then re-confirm the booking.';
        } else {
            $n = max(1, (int)$b['nights']);
            $out['sources'][] = [
                'description' => 'Accommodation — ' . ($h['room_name'] ?: 'Room') . ', ' . date('j M', strtotime((string)$h['check_in']))
                               . ' – ' . date('j M Y', strtotime((string)$h['check_out'])) . " ({$n} night" . ($n === 1 ? '' : 's') . ')',
                'cents' => acct_cents($b['gross_amount']), 'currency' => strtoupper((string)$b['currency']),
                'category' => 'room', 'source_kind' => 'stay', 'source_id' => $holdId,
            ];
        }
    }

    // Confirmed / completed requests. NULL price = unpriced → blocks (correction #3); 0 = complimentary → no line.
    require_once __DIR__ . '/booking.php';   // addon_label() — loaded lazily so the POS / inventory hooks stay light
    $addonCur = company_column_exists('booking_addons', 'price_currency');
    foreach (db_query("SELECT ba.*, t.name AS tour_name FROM booking_addons ba LEFT JOIN tours t ON t.id = ba.tour_id
                        WHERE ba.hold_id = :h AND ba.status IN ('confirmed','completed') AND ba.document_id IS NULL ORDER BY ba.created_at, ba.id",
                     [':h' => $holdId])->fetchAll() as $a) {
        if ($a['price_amount'] === null) { $out['blockers'][] = '"' . addon_label($a) . '" has no price — set it (or 0 if free) first.'; continue; }
        if ((float)$a['price_amount'] <= 0) continue;
        $out['sources'][] = [
            'description' => addon_label($a) . (!empty($a['pax']) ? ' · ' . (int)$a['pax'] . ' pax' : ''),
            'cents' => acct_cents($a['price_amount']), 'currency' => strtoupper((string)(($addonCur ? $a['price_currency'] : null) ?: $site)),
            'category' => acct_category_for_addon((string)$a['kind']), 'source_kind' => 'addon', 'source_id' => (int)$a['id'],
        ];
    }

    // Other charges, including POS room charges.
    $posOn = to_regclass_exists('pos_sales') && company_column_exists('bill_items', 'pos_sale_id');
    foreach (db_query('SELECT * FROM bill_items WHERE hold_id = :h AND document_id IS NULL ORDER BY id', [':h' => $holdId])->fetchAll() as $it) {
        if ((float)$it['amount'] <= 0) continue;
        $cur = strtoupper((string)($it['currency'] ?: $site));
        $src = ['description' => (string)$it['label'], 'cents' => acct_cents($it['amount']), 'currency' => $cur,
                'category' => 'other', 'source_kind' => 'bill_item', 'source_id' => (int)$it['id']];
        if ($posOn && !empty($it['pos_sale_id'])) {
            $pos = acct_pos_room_charge($it, $co);
            if (isset($pos['blocker'])) { $out['blockers'][] = $pos['blocker']; continue; }
            foreach ($pos['sources'] as $s) $out['sources'][] = $s + ['currency' => $cur, 'source_kind' => 'bill_item', 'source_id' => (int)$it['id']];
            continue;
        }
        $out['sources'][] = $src;
    }
    return $out;
}

/**
 * A POS room charge on the folio (one bill line). Same company → revenue lines with
 * the sale's own VAT, the tip split out as a non-revenue line. Another company →
 * 'on_behalf' (default): one disbursement line (no VAT, not this company's revenue);
 * 'reinvoice': revenue lines under THIS company's VAT rule (§4.4). An outlet with no
 * company blocks the invoice (fail closed once live).
 */
function acct_pos_room_charge(array $item, array $co): array {
    $s = db_query('SELECT s.*, o.kind AS outlet_kind, o.name AS outlet_name FROM pos_sales s JOIN pos_outlets o ON o.id = s.outlet_id WHERE s.id = :s',
        [':s' => (int)$item['pos_sale_id']])->fetch();
    $cents = acct_cents($item['amount']);
    if (!$s) return ['sources' => [['description' => (string)$item['label'], 'cents' => $cents, 'category' => 'other']]];
    $sellerId = company_for_outlet((int)$s['outlet_id']);
    if (!$sellerId) return ['blocker' => "The POS outlet \"{$s['outlet_name']}\" has no company — assign it in Accounting → Companies."];
    // Portion of the bill line that is tip / VAT, in the BILL currency at the sale's frozen rate.
    $fx   = (float)($s['fx_rate'] ?? 0);
    $conv = function (float $saleAmt) use ($s, $fx): int {
        $billCur = strtoupper((string)($s['bill_currency'] ?? ''));
        if ($billCur === '' || $billCur === strtoupper((string)$s['currency']) || $fx <= 0) return acct_cents($saleAmt);
        return (int) round(acct_cents($saleAmt) / $fx, 0, PHP_ROUND_HALF_UP);
    };
    $tip   = min($cents, $conv((float)($s['tip_amount'] ?? 0)));
    $goods = $cents - $tip;
    $cat   = acct_category_for_outlet((string)$s['outlet_kind']);
    $label = (string)$item['label'];

    if ($sellerId !== (int)$co['id']) {
        $seller = company_fetch($sellerId);
        if (($seller['room_charge_mode'] ?? 'on_behalf') === 'on_behalf') {
            return ['sources' => [['description' => $label . ' — collected for ' . ($seller['name'] ?? 'another company'), 'cents' => $cents,
                                   'category' => 'disbursement', 'is_disbursement' => true, 'supplier_company_id' => $sellerId]]];
        }
        $out = [['description' => $label, 'cents' => $goods, 'category' => $cat]];   // re-invoiced: this company's VAT rule
    } else {
        $vat = min($goods, $conv((float)($s['vat_amount'] ?? 0)));
        $out = [['description' => $label, 'cents' => $goods, 'category' => $cat, 'vat_snapshot' => ['rate' => (float)($s['vat_pct'] ?? 0), 'vat_cents' => $vat]]];
    }
    if ($tip > 0) $out[] = ['description' => 'Tip — ' . $s['outlet_name'], 'cents' => $tip, 'category' => 'tip'];
    return ['sources' => $out];
}

/** The bookings-ledger row of a hold (null pre-migration / not synced). */
function bookings_row_for_hold(int $holdId): ?array {
    if (!to_regclass_exists('bookings')) return null;
    $r = db_query('SELECT * FROM bookings WHERE hold_id = :h', [':h' => $holdId])->fetch();
    return $r ?: null;
}

/**
 * SQL columns for a document's money status: allocated (payments applied),
 * credited (sum of its credit notes) and the credit note numbers. $a = alias.
 */
function acct_doc_status_sql(string $a): string {
    return "COALESCE((SELECT SUM(al.amount) FROM acct_allocations al WHERE al.document_id = {$a}.id), 0) AS allocated,
            COALESCE((SELECT SUM(cn.total) FROM acct_documents cn WHERE cn.credits_document_id = {$a}.id), 0) AS credited,
            (SELECT string_agg(cn.number, ', ' ORDER BY cn.id) FROM acct_documents cn WHERE cn.credits_document_id = {$a}.id) AS credited_by_number,
            (SELECT o.number FROM acct_documents o WHERE o.id = {$a}.credits_document_id) AS credits_number";
}

/**
 * Add balance_cents / credited_cents / fully_credited to a document row (see
 * acct_doc_status_sql()). An invoice's balance = total − credited − paid; a credit
 * note has none. Pure.
 */
function acct_doc_with_balance(array $d): array {
    $isInv = in_array($d['doc_type'], ['invoice', 'ic_invoice'], true);
    $d['credited_cents'] = acct_cents($d['credited'] ?? 0);
    $d['fully_credited'] = $isInv && $d['credited_cents'] >= acct_cents($d['total']);
    $d['credited_by']    = $d['fully_credited'];   // kept for the P2a views
    $d['balance_cents']  = $isInv ? max(0, acct_cents($d['total']) - $d['credited_cents'] - acct_cents($d['allocated'] ?? 0)) : 0;
    return $d;
}

/** A document's lines, each with 'credited' (bool) — for choosing what to credit. */
function acct_document_lines_status(int $docId): array {
    return array_map(fn($l) => $l + ['credited' => companies_bool($l['is_credited'])], db_query(
        'SELECT l.*, EXISTS (SELECT 1 FROM acct_document_lines c WHERE c.credits_line_id = l.id) AS is_credited
           FROM acct_document_lines l WHERE l.document_id = :d ORDER BY l.id', [':d' => $docId])->fetchAll());
}

/** Documents of a hold with their balances (cents), oldest first. */
function acct_hold_documents(int $holdId): array {
    $rows = db_query(
        "SELECT d.*, " . acct_doc_status_sql('d') . "
           FROM acct_documents d WHERE d.hold_id = :h ORDER BY d.issued_at, d.id", [':h' => $holdId])->fetchAll();
    return array_map('acct_doc_with_balance', $rows);
}

/** Payments of a hold with what is still unapplied (cents), oldest first. */
function acct_hold_payments(int $holdId): array {
    $rows = db_query(
        "SELECT p.*, ca.label AS account_label, au.name AS recorded_by_name,
                COALESCE((SELECT SUM(a.pay_amount) FROM acct_allocations a WHERE a.payment_id = p.id), 0) AS allocated,
                COALESCE((SELECT SUM(r.amount) FROM acct_payments r WHERE r.refunds_payment_id = p.id), 0) AS refunded
           FROM acct_payments p LEFT JOIN company_accounts ca ON ca.id = p.account_id
           LEFT JOIN admin_users au ON au.id = p.recorded_by
          WHERE p.hold_id = :h ORDER BY p.received_at, p.id", [':h' => $holdId])->fetchAll();
    foreach ($rows as &$p) {
        $p['available_cents'] = $p['kind'] === 'receipt'
            ? acct_cents($p['amount']) - acct_cents($p['allocated']) - acct_cents($p['refunded']) : 0;
    }
    return $rows;
}

/**
 * The whole folio for the Bill tab: open charges, documents, payments, and per
 * currency what is still due. Reads only.
 */
function acct_folio(int $holdId): array {
    $ctx = acct_hold_context($holdId);
    $f = ['ctx' => $ctx, 'lines' => [], 'blockers' => [], 'notes' => [], 'docs' => [], 'payments' => [], 'by_cur' => []];
    if (!$ctx['company']) return $f;
    $open = acct_open_sources($ctx);
    $f['blockers'] = $open['blockers']; $f['notes'] = $open['notes'];
    foreach ($open['sources'] as $s) $f['lines'][] = acct_build_line($s, $ctx['company']) + ['currency' => $s['currency']];
    $f['docs']     = acct_hold_documents($holdId);
    $f['payments'] = acct_hold_payments($holdId);
    $cur = function (string $c) use (&$f): void { $f['by_cur'][$c] ??= ['open' => 0, 'invoiced_due' => 0, 'unapplied' => 0, 'deposits' => 0, 'due' => 0]; };
    foreach ($f['lines'] as $l) { $cur($l['currency']); $f['by_cur'][$l['currency']]['open'] += $l['gross_cents']; }
    foreach ($f['docs'] as $d) { if ($d['balance_cents'] > 0) { $cur($d['currency']); $f['by_cur'][$d['currency']]['invoiced_due'] += $d['balance_cents']; } }
    foreach ($f['payments'] as $p) {
        if ($p['available_cents'] <= 0) continue;
        $cur($p['currency']);
        $f['by_cur'][$p['currency']][companies_bool($p['is_security_deposit']) ? 'deposits' : 'unapplied'] += $p['available_cents'];
    }
    foreach ($f['by_cur'] as $c => &$b) $b['due'] = $b['open'] + $b['invoiced_due'] - $b['unapplied'];
    unset($b);
    ksort($f['by_cur']);
    return $f;
}

// ── Writes ──────────────────────────────────────────────────────────────────

/**
 * Issue the tax invoice(s) for everything open on a folio — one per currency — and
 * apply the hold's unapplied payments to them. $only limits it to some source kinds
 * (['stay'] = the stay alone, used when a company invoices at confirmation).
 * Charges collected on behalf of another company add an inter-company entry and
 * settle that company's own sale document. Returns the new document ids.
 */
function acct_issue_folio(int $holdId, ?int $userId, string $buyerPin = '', ?array $only = null): array {
    $buyerPin = company_normalize_pin($buyerPin);
    if (($p = company_pin_problem($buyerPin)) !== null) throw new AcctRefusal("Buyer's KRA PIN: " . $p);
    return company_tx(function () use ($holdId, $userId, $buyerPin, $only): array {
        db_query('SELECT id FROM holds WHERE id = :h FOR UPDATE', [':h' => $holdId]);   // one issuer per folio at a time
        $ctx = acct_hold_context($holdId);
        if (!$ctx['live']) throw new AcctRefusal($ctx['reason'] ?: 'Invoicing is not live for this booking.');
        $co = $ctx['company']; $h = $ctx['hold'];
        $open = acct_open_sources($ctx);
        $sources = $only === null ? $open['sources'] : array_values(array_filter($open['sources'], fn($s) => in_array($s['source_kind'], $only, true)));
        $blockers = $only === null ? $open['blockers'] : ($open['stay_blocker'] !== null && in_array('stay', $only, true) ? [$open['stay_blocker']] : []);
        if ($blockers) throw new AcctRefusal(implode(' ', $blockers));
        if (!$sources) throw new AcctRefusal('There is nothing new to invoice.');

        $byCur = [];
        foreach ($sources as $s) $byCur[$s['currency']][] = $s;
        ksort($byCur);
        $isAgent = !empty($h['agent_id']) && ($h['agent_agency'] || $h['agent_name']);
        $customer = $isAgent ? trim((string)($h['agent_agency'] ?: $h['agent_name'])) . ' (for ' . $h['guest_name'] . ')' : (string)$h['guest_name'];
        $ids = [];
        foreach ($byCur as $cur => $srcs) {
            $lines = array_map(fn($s) => acct_build_line($s, $co), $srcs);
            $docId = acct_insert_document($co, 'invoice', $cur, $lines, [
                'customer_kind' => $isAgent ? 'agent' : 'guest', 'customer_name' => $customer, 'customer_pin' => $buyerPin,
                'agent_id' => $isAgent ? (int)$h['agent_id'] : null, 'hold_id' => $holdId, 'issued_by' => $userId]);
            foreach ($lines as $l) {
                if ($l['source_kind'] === 'addon')     db_query('UPDATE booking_addons SET document_id = :d WHERE id = :i AND document_id IS NULL', [':d' => $docId, ':i' => $l['source_id']]);
                if ($l['source_kind'] === 'bill_item') db_query('UPDATE bill_items SET document_id = :d WHERE id = :i AND document_id IS NULL', [':d' => $docId, ':i' => $l['source_id']]);
            }
            acct_ic_after_folio_invoice($docId, $co, $userId);
            $ids[] = $docId;
        }
        acct_auto_allocate($holdId);
        return $ids;
    });
}

/**
 * Write one document + its lines under the next number of its series. $meta:
 * customer_kind, customer_name, customer_pin, agent_id, counterparty_company_id,
 * hold_id, pos_sale_id, transfer_ref, credits_document_id, reason, issued_by.
 * Call inside a transaction (acct_next_number() refuses otherwise). Returns the id.
 */
function acct_insert_document(array $co, string $type, string $cur, array $lines, array $meta): int {
    $fx = acct_fx_rate((string)$co['home_currency'], $cur);
    if ($fx === null) throw new AcctRefusal("No exchange rate from {$cur} to {$co['home_currency']} — set it under currency settings first.");
    $t = acct_totals($lines);
    $number = acct_next_number((int)$co['id'], $type);
    db_query('INSERT INTO acct_documents (company_id, doc_type, number, customer_kind, customer_name, customer_pin, agent_id, counterparty_company_id,
                                          currency, fx_to_home, subtotal, vat_amount, total, hold_id, pos_sale_id, transfer_ref,
                                          credits_document_id, reason, issued_by)
              VALUES (:c, :t, :n, :ck, :cn, :cp, :ag, :cc, :cur, :fx, :sub, :vat, :tot, :h, :ps, :tr, :cr, :why, :u)',
        [':c' => $co['id'], ':t' => $type, ':n' => $number, ':ck' => $meta['customer_kind'] ?? 'walkin',
         ':cn' => mb_substr((string)($meta['customer_name'] ?? ''), 0, 200), ':cp' => (string)($meta['customer_pin'] ?? ''),
         ':ag' => $meta['agent_id'] ?? null, ':cc' => $meta['counterparty_company_id'] ?? null, ':cur' => $cur, ':fx' => $fx,
         ':sub' => acct_from_cents($t['net']), ':vat' => acct_from_cents($t['vat']), ':tot' => acct_from_cents($t['gross']),
         ':h' => $meta['hold_id'] ?? null, ':ps' => $meta['pos_sale_id'] ?? null, ':tr' => $meta['transfer_ref'] ?? null,
         ':cr' => $meta['credits_document_id'] ?? null, ':why' => (string)($meta['reason'] ?? ''), ':u' => $meta['issued_by'] ?? null]);
    $docId = (int) db()->lastInsertId();
    acct_insert_lines($docId, $lines);
    return $docId;
}

/** Insert built lines under a document. */
function acct_insert_lines(int $docId, array $lines): void {
    foreach ($lines as $l) {
        db_query('INSERT INTO acct_document_lines (document_id, description, qty, unit_price, line_total, net_amount, vat_amount, vat_rate,
                                                    tax_band, category, is_disbursement, supplier_company_id, source_kind, source_id, credits_line_id)
                  VALUES (:d, :ds, 1, :up, :lt, :net, :vat, :vr, :b, :cat, :disb, :sup, :sk, :si, :cl)',
            [':d' => $docId, ':ds' => $l['description'], ':up' => acct_from_cents($l['gross_cents']), ':lt' => acct_from_cents($l['gross_cents']),
             ':net' => acct_from_cents($l['net_cents']), ':vat' => acct_from_cents($l['vat_cents']), ':vr' => $l['vat_rate'], ':b' => $l['tax_band'],
             ':cat' => $l['category'], ':disb' => $l['is_disbursement'] ? 'TRUE' : 'FALSE', ':sup' => $l['supplier_company_id'],
             ':sk' => $l['source_kind'], ':si' => $l['source_id'], ':cl' => $l['credits_line_id'] ?? null]);
    }
}

/** Apply the hold's unapplied (non-deposit) receipts to its open invoices. Call inside a transaction. */
function acct_auto_allocate(int $holdId): int {
    $docs = []; $pays = [];
    foreach (acct_hold_documents($holdId) as $d) if ($d['balance_cents'] > 0) $docs[] = ['id' => (int)$d['id'], 'currency' => $d['currency'], 'balance' => $d['balance_cents']];
    foreach (acct_hold_payments($holdId) as $p) {
        if ($p['kind'] === 'receipt' && !companies_bool($p['is_security_deposit']) && $p['available_cents'] > 0) {
            $pays[] = ['id' => (int)$p['id'], 'currency' => $p['currency'], 'available' => $p['available_cents']];
        }
    }
    if (!$docs || !$pays) return 0;
    $plan = acct_allocation_plan($docs, $pays, fn($from, $to) => acct_fx_rate($from, $to));
    foreach ($plan as $a) {
        db_query('INSERT INTO acct_allocations (payment_id, document_id, amount, pay_amount, rate) VALUES (:p, :d, :a, :pa, :r)',
            [':p' => $a['payment_id'], ':d' => $a['document_id'], ':a' => acct_from_cents($a['amount']), ':pa' => acct_from_cents($a['pay_amount']), ':r' => $a['rate']]);
    }
    return count($plan);
}

/**
 * Record money received for a booking. The account must belong to the folio's
 * company; the payment is in the account's currency. Returns the payment id.
 */
function acct_record_payment(int $holdId, array $in, ?int $userId): int {
    $method = (string)($in['method'] ?? '');
    if (!isset(ACCT_PAY_METHODS[$method])) throw new AcctRefusal('Pick how it was paid.');
    $raw = trim((string)($in['amount'] ?? ''));
    if (!is_numeric($raw) || (float)$raw <= 0 || (float)$raw > ACCT_MAX_AMOUNT) throw new AcctRefusal('Enter the amount received.');
    $amount = acct_from_cents(acct_cents($raw));
    $ref = trim((string)($in['reference'] ?? ''));
    if (mb_strlen($ref) > 80) throw new AcctRefusal('The reference is up to 80 characters.');
    if ($method === 'mpesa' && $ref === '') throw new AcctRefusal('Add the M-Pesa confirmation code as the reference.');
    $deposit = !empty($in['is_security_deposit']);
    $date = trim((string)($in['received_on'] ?? ''));
    $today = date('Y-m-d');
    if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date) || $date > $today)) throw new AcctRefusal('The date received cannot be in the future.');
    return company_tx(function () use ($holdId, $in, $userId, $method, $amount, $ref, $deposit, $date, $today): int {
        db_query('SELECT id FROM holds WHERE id = :h FOR UPDATE', [':h' => $holdId]);
        $ctx = acct_hold_context($holdId);
        if (!$ctx['live']) throw new AcctRefusal($ctx['reason'] ?: 'Invoicing is not live for this booking.');
        $co = $ctx['company'];
        $acc = db_query('SELECT * FROM company_accounts WHERE id = :a AND company_id = :c AND is_active', [':a' => (int)($in['account_id'] ?? 0), ':c' => $co['id']])->fetch();
        if (!$acc) throw new AcctRefusal("Pick one of {$co['name']}'s accounts.");
        if (!acct_method_fits_account($method, (string)$acc['kind'])) {
            throw new AcctRefusal(ACCT_PAY_METHODS[$method] . ' can’t go into ' . $acc['label'] . ' (' . (COMPANY_ACCOUNT_KINDS[$acc['kind']] ?? $acc['kind']) . ') — pick the matching account.');
        }
        $fx = acct_fx_rate((string)$co['home_currency'], (string)$acc['currency']);
        if ($fx === null) throw new AcctRefusal("No exchange rate from {$acc['currency']} to {$co['home_currency']}.");
        $at = ($date === '' || $date === $today) ? date('Y-m-d H:i:s') : $date . ' 12:00:00';
        db_query('INSERT INTO acct_payments (company_id, account_id, kind, is_security_deposit, method, amount, currency, fx_to_home, reference, payer_name, hold_id, received_at, recorded_by)
                  VALUES (:c, :a, \'receipt\', :dep, :m, :amt, :cur, :fx, :ref, :payer, :h, :at, :u)',
            [':c' => $co['id'], ':a' => $acc['id'], ':dep' => $deposit ? 'TRUE' : 'FALSE', ':m' => $method, ':amt' => $amount,
             ':cur' => $acc['currency'], ':fx' => $fx, ':ref' => $ref, ':payer' => mb_substr(trim((string)($in['payer_name'] ?? $ctx['hold']['guest_name'])), 0, 200),
             ':h' => $holdId, ':at' => $at, ':u' => $userId]);
        $id = (int) db()->lastInsertId();
        if (!$deposit) acct_auto_allocate($holdId);
        return $id;
    });
}

/** Cents of a receipt not applied to an invoice and not refunded (0 for a refund row). */
function acct_payment_available(int $paymentId): int {
    $r = db_query("SELECT p.kind, p.amount,
                          COALESCE((SELECT SUM(a.pay_amount) FROM acct_allocations a WHERE a.payment_id = p.id), 0) AS allocated,
                          COALESCE((SELECT SUM(x.amount) FROM acct_payments x WHERE x.refunds_payment_id = p.id), 0) AS refunded
                     FROM acct_payments p WHERE p.id = :p", [':p' => $paymentId])->fetch();
    if (!$r || $r['kind'] !== 'receipt') return 0;
    return acct_cents($r['amount']) - acct_cents($r['allocated']) - acct_cents($r['refunded']);
}

/**
 * Give money back from a receipt (a mistake, or a security deposit returned). Only
 * the part not applied to an invoice can be refunded — credit the invoice first.
 */
function acct_refund_payment(int $paymentId, string $amountRaw, string $reason, ?int $userId): int {
    $reason = trim($reason);
    if (mb_strlen($reason) < 3) throw new AcctRefusal('Give a reason for the refund.');
    if (!is_numeric($amountRaw) || (float)$amountRaw <= 0) throw new AcctRefusal('Enter the amount to refund.');
    $cents = acct_cents($amountRaw);
    return company_tx(function () use ($paymentId, $cents, $reason, $userId): int {
        $p = db_query("SELECT * FROM acct_payments WHERE id = :p AND kind = 'receipt' FOR UPDATE", [':p' => $paymentId])->fetch();
        if (!$p) throw new AcctRefusal('That payment no longer exists.');
        $avail = acct_payment_available($paymentId);
        if ($cents > $avail) {
            throw new AcctRefusal($avail > 0
                ? 'Only ' . acct_money($avail, $p['currency']) . ' of this payment is not on an invoice — refund at most that, or credit the invoice first.'
                : 'All of this payment is on an invoice — credit the invoice first.');
        }
        db_query('INSERT INTO acct_payments (company_id, account_id, kind, refunds_payment_id, is_security_deposit, method, amount, currency, fx_to_home,
                                             reference, payer_name, reason, hold_id, pos_sale_id, counterparty_company_id, recorded_by)
                  VALUES (:c, :a, \'refund\', :r, :dep, :m, :amt, :cur, :fx, :ref, :payer, :why, :h, :ps, :cp, :u)',
            [':c' => $p['company_id'], ':a' => $p['account_id'], ':r' => $paymentId, ':dep' => companies_bool($p['is_security_deposit']) ? 'TRUE' : 'FALSE',
             ':m' => $p['method'], ':amt' => acct_from_cents($cents), ':cur' => $p['currency'], ':fx' => $p['fx_to_home'],
             ':ref' => $p['reference'], ':payer' => $p['payer_name'], ':why' => $reason, ':h' => $p['hold_id'],
             ':ps' => $p['pos_sale_id'] ?? null, ':cp' => $p['counterparty_company_id'] ?? null, ':u' => $userId]);   // a till refund stays linked to its sale
        return (int) db()->lastInsertId();
    });
}

/**
 * Credit an invoice (or an inter-company invoice) — in full, or only $lineIds.
 * The credit note mirrors the chosen lines (each line is credited at most once);
 * their charges go back onto the open folio to fix and re-issue; any payment now
 * exceeding what is left owed is released back to "unapplied"; inter-company
 * amounts behind the lines are reversed. Everything by appending rows. Returns the
 * credit note id.
 */
function acct_credit_document(int $docId, string $reason, ?int $userId, ?array $lineIds = null): int {
    $reason = trim($reason);
    if (mb_strlen($reason) < 3) throw new AcctRefusal('Give a reason for the credit note.');
    return company_tx(function () use ($docId, $reason, $userId, $lineIds): int {
        $d = db_query('SELECT * FROM acct_documents WHERE id = :d FOR UPDATE', [':d' => $docId])->fetch();
        if (!$d || !in_array($d['doc_type'], ['invoice', 'ic_invoice'], true)) throw new AcctRefusal('Only an invoice can be credited.');
        if ($d['hold_id']) db_query('SELECT id FROM holds WHERE id = :h FOR UPDATE', [':h' => $d['hold_id']]);
        $open = db_query('SELECT l.* FROM acct_document_lines l WHERE l.document_id = :d
                           AND NOT EXISTS (SELECT 1 FROM acct_document_lines c WHERE c.credits_line_id = l.id) ORDER BY l.id', [':d' => $docId])->fetchAll();
        if ($lineIds !== null) {
            $want = array_values(array_unique(array_map('intval', $lineIds)));
            if (!$want) throw new AcctRefusal('Tick the lines to credit.');
            $open = array_values(array_filter($open, fn($l) => in_array((int)$l['id'], $want, true)));
            if (count($open) !== count($want)) throw new AcctRefusal('Some of those lines are not on this invoice or are already credited.');
        }
        if (!$open) throw new AcctRefusal("{$d['number']} has already been credited in full.");
        $co = company_fetch((int)$d['company_id']);
        $lines = array_map(fn($l) => [
            'description' => $l['description'], 'category' => $l['category'], 'is_disbursement' => companies_bool($l['is_disbursement']),
            'supplier_company_id' => $l['supplier_company_id'], 'source_kind' => $l['source_kind'], 'source_id' => $l['source_id'],
            'net_cents' => acct_cents($l['net_amount']), 'vat_cents' => acct_cents($l['vat_amount']), 'gross_cents' => acct_cents($l['line_total']),
            'vat_rate' => (float)$l['vat_rate'], 'tax_band' => $l['tax_band'], 'credits_line_id' => (int)$l['id'],
        ], $open);
        $cn = acct_insert_document($co, 'credit_note', (string)$d['currency'], $lines, [
            'customer_kind' => $d['customer_kind'], 'customer_name' => $d['customer_name'], 'customer_pin' => $d['customer_pin'],
            'agent_id' => $d['agent_id'], 'counterparty_company_id' => $d['counterparty_company_id'], 'hold_id' => $d['hold_id'],
            'credits_document_id' => $docId, 'reason' => $reason, 'issued_by' => $userId]);

        // Release: the credited charges become open again (only those lines).
        foreach ($open as $l) {
            if ($l['source_kind'] === 'bill_item') db_query('UPDATE bill_items SET document_id = NULL WHERE id = :i AND document_id = :d', [':i' => $l['source_id'], ':d' => $docId]);
            if ($l['source_kind'] === 'addon')     db_query('UPDATE booking_addons SET document_id = NULL WHERE id = :i AND document_id = :d', [':i' => $l['source_id'], ':d' => $docId]);
        }
        // Money: whatever is applied beyond what is still owed goes back to "unapplied", newest payment first.
        $row = acct_doc_with_balance(db_query('SELECT d.*, ' . acct_doc_status_sql('d') . ' FROM acct_documents d WHERE d.id = :d', [':d' => $docId])->fetch());
        $excess = acct_cents($row['allocated']) - max(0, acct_cents($row['total']) - $row['credited_cents']);
        if ($excess > 0) {
            foreach (db_query('SELECT payment_id, SUM(amount) AS amt, SUM(pay_amount) AS pay, MAX(rate) AS rate FROM acct_allocations
                                WHERE document_id = :d GROUP BY payment_id HAVING SUM(amount) > 0 ORDER BY payment_id DESC', [':d' => $docId])->fetchAll() as $a) {
                if ($excess <= 0) break;
                $take = min($excess, acct_cents($a['amt']));
                $pay  = $take === acct_cents($a['amt']) ? acct_cents($a['pay']) : (int) round($take * (float)$a['rate'], 0, PHP_ROUND_HALF_UP);
                db_query('INSERT INTO acct_allocations (payment_id, document_id, amount, pay_amount, rate) VALUES (:p, :d, :a, :pa, :r)',
                    [':p' => $a['payment_id'], ':d' => $docId, ':a' => -acct_from_cents($take), ':pa' => -acct_from_cents($pay), ':r' => $a['rate']]);
                $excess -= $take;
            }
        }
        acct_ic_after_credit($d, $open, $cn, $userId);
        if ($d['hold_id']) acct_auto_allocate((int)$d['hold_id']);
        return $cn;
    });
}

/** Credit an invoice in full (P2a name). */
function acct_credit_invoice(int $docId, string $reason, ?int $userId): int {
    return acct_credit_document($docId, $reason, $userId);
}

/**
 * Owner: switch invoicing on for a company (the go-live date) and say whether its
 * room / extras prices include VAT. Once the company has issued a document both
 * are locked — moving the date would pull issued invoices in or out of accounting,
 * and changing VAT-inclusiveness would re-price every open folio.
 */
function acct_set_company_invoicing(int $companyId, string $startsOn, bool $pricesIncludeVat): void {
    $startsOn = trim($startsOn);
    if ($startsOn !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startsOn) || !strtotime($startsOn))) throw new AcctRefusal('Pick the date invoicing starts.');
    company_tx(function () use ($companyId, $startsOn, $pricesIncludeVat): void {
        $co = db_query('SELECT * FROM companies WHERE id = :c FOR UPDATE', [':c' => $companyId])->fetch();
        if (!$co) throw new AcctRefusal('That company no longer exists.');
        $issued = (bool) db_query('SELECT 1 FROM acct_documents WHERE company_id = :c LIMIT 1', [':c' => $companyId])->fetchColumn();
        $same = (string)($co['accounting_starts_on'] ?? '') === $startsOn && companies_bool($co['prices_include_vat']) === $pricesIncludeVat;
        if ($issued && !$same) throw new AcctRefusal("{$co['name']} has already issued invoices, so its start date and VAT setting are locked.");
        if ($startsOn !== '') {
            if ($co['kra_pin'] === '') throw new AcctRefusal('Add the company\'s KRA PIN before switching invoicing on.');
            if (!db_query('SELECT 1 FROM company_accounts WHERE company_id = :c AND is_active LIMIT 1', [':c' => $companyId])->fetchColumn()) {
                throw new AcctRefusal('Add at least one money account before switching invoicing on.');
            }
            // Once any company invoices, a till with no company refuses to sell (fail closed) — so
            // every open outlet must belong to someone first.
            $gaps = company_ownership_gaps()['outlets'];
            if ($gaps) throw new AcctRefusal('Assign a company to every open POS outlet first (' . implode(', ', array_column($gaps, 'name')) . ') — otherwise those tills would stop selling.');
        }
        db_query('UPDATE companies SET accounting_starts_on = :d, prices_include_vat = :v, updated_at = now() WHERE id = :c',
            [':d' => $startsOn === '' ? null : $startsOn, ':v' => $pricesIncludeVat ? 'TRUE' : 'FALSE', ':c' => $companyId]);
    });
}

/** Has this company issued any document yet? (false pre-migration) */
function acct_company_has_documents(int $companyId): bool {
    return acct_supported() && (bool) db_query('SELECT 1 FROM acct_documents WHERE company_id = :c LIMIT 1', [':c' => $companyId])->fetchColumn();
}

// ── Locks used by the existing bill / request / POS paths ───────────────────

/** Is this bill line on an issued invoice? (false pre-migration) */
function acct_bill_item_locked(int $itemId): bool {
    return acct_supported() && (bool) db_query('SELECT 1 FROM bill_items WHERE id = :i AND document_id IS NOT NULL', [':i' => $itemId])->fetchColumn();
}

/** Is this request on an issued invoice? (false pre-migration) */
function acct_addon_locked(int $addonId): bool {
    return acct_supported() && (bool) db_query('SELECT 1 FROM booking_addons WHERE id = :a AND document_id IS NOT NULL', [':a' => $addonId])->fetchColumn();
}

/** Is a POS sale's room charge on an issued invoice? (false pre-migration) */
function acct_pos_sale_locked(int $saleId): bool {
    return acct_supported() && company_column_exists('bill_items', 'pos_sale_id')
        && (bool) db_query('SELECT 1 FROM bill_items WHERE pos_sale_id = :s AND document_id IS NOT NULL', [':s' => $saleId])->fetchColumn();
}

/** The currency to stamp on a new bill line / priced request (NULL-safe pre-migration). */
function acct_bill_currency_supported(): bool { return acct_supported() && company_column_exists('bill_items', 'currency'); }

// ── Documents list / export ─────────────────────────────────────────────────

/**
 * Documents in a window, scoped. $venueIds null = all (owner). Filters: company_id,
 * doc_type, from, to (Y-m-d, inclusive).
 */
function acct_documents_list(array $f, ?array $venueIds): array {
    $w = ['d.issued_at >= :from', 'd.issued_at < (CAST(:to AS date) + 1)'];
    $a = [':from' => $f['from'], ':to' => $f['to']];
    if (!empty($f['company_id'])) { $w[] = 'd.company_id = :c'; $a[':c'] = (int)$f['company_id']; }
    if (!empty($f['doc_type']))   { $w[] = 'd.doc_type = :t';   $a[':t'] = $f['doc_type']; }
    if ($venueIds !== null) $w[] = acct_scope_sql('d', $venueIds);
    return array_map('acct_doc_with_balance', db_query(
        "SELECT d.*, co.name AS company_name, co.code AS company_code, h.guest_name, v.name AS venue_name, cp.name AS counterparty_name,
                " . acct_doc_status_sql('d') . "
           FROM acct_documents d JOIN companies co ON co.id = d.company_id LEFT JOIN companies cp ON cp.id = d.counterparty_company_id
           LEFT JOIN holds h ON h.id = d.hold_id LEFT JOIN units u ON u.id = h.unit_id
           LEFT JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u') . " LEFT JOIN venues v ON v.id = r.venue_id
          WHERE " . implode(' AND ', $w) . "
          ORDER BY d.issued_at DESC, d.id DESC LIMIT 2000", $a)->fetchAll());
}

/**
 * Manager scope for a document / payment row alias $a (joined to holds h → rooms r):
 * a booking's row by the booking's property; anything else (POS, inter-company) by
 * the companies that own the manager's properties.
 */
function acct_scope_sql(string $a, array $venueIds): string {
    if (!$venueIds) return 'FALSE';
    $v = implode(',', array_map('intval', $venueIds));
    $cos = "(SELECT company_id FROM venues WHERE id IN ({$v}) AND company_id IS NOT NULL)";
    $cp  = $a === 'd' ? " OR {$a}.counterparty_company_id IN {$cos}" : '';
    return "(({$a}.hold_id IS NOT NULL AND r.venue_id IN ({$v})) OR ({$a}.hold_id IS NULL AND ({$a}.company_id IN {$cos}{$cp})))";
}

/** Payments in a window, scoped like acct_documents_list(). */
function acct_payments_list(array $f, ?array $venueIds): array {
    $w = ['p.received_at >= :from', 'p.received_at < (CAST(:to AS date) + 1)'];
    $a = [':from' => $f['from'], ':to' => $f['to']];
    if (!empty($f['company_id'])) { $w[] = 'p.company_id = :c'; $a[':c'] = (int)$f['company_id']; }
    if ($venueIds !== null) $w[] = acct_scope_sql('p', $venueIds);
    return db_query(
        "SELECT p.*, co.name AS company_name, COALESCE(ca.label, 'Inter-company') AS account_label, h.guest_name, v.name AS venue_name
           FROM acct_payments p JOIN companies co ON co.id = p.company_id LEFT JOIN company_accounts ca ON ca.id = p.account_id
           LEFT JOIN holds h ON h.id = p.hold_id LEFT JOIN units u ON u.id = h.unit_id
           LEFT JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u') . " LEFT JOIN venues v ON v.id = r.venue_id
          WHERE " . implode(' AND ', $w) . "
          ORDER BY p.received_at DESC, p.id DESC LIMIT 2000", $a)->fetchAll();
}

/** Per-company, per-currency totals of a documents list (credit notes subtract). Pure. */
function acct_documents_summary(array $docs): array {
    $s = [];
    foreach ($docs as $d) {
        $k = $d['company_name'] . '|' . $d['currency'];
        $s[$k] ??= ['company' => $d['company_name'], 'currency' => $d['currency'], 'net' => 0, 'vat' => 0, 'total' => 0, 'count' => 0];
        $sign = $d['doc_type'] === 'credit_note' ? -1 : 1;
        $s[$k]['net']   += $sign * acct_cents($d['subtotal']);
        $s[$k]['vat']   += $sign * acct_cents($d['vat_amount']);
        $s[$k]['total'] += $sign * acct_cents($d['total']);
        $s[$k]['count']++;
    }
    ksort($s);
    return array_values($s);
}

require_once __DIR__ . '/acct-ops.php';   // P2b: POS documents, inter-company, deposits, confirm hooks
