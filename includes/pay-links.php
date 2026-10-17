<?php
declare(strict_types=1);
/**
 * Payment links — ask a guest to pay by Paystack for a room bill, an activity, a
 * deposit, a POS sale or any other amount. Pages: admin/payments.php (generator +
 * list), the booking Bill tab (admin/_ws_paylinks.php), the till (api/pos/paylink.php),
 * the guest's page (pay.php). Paystack calls: includes/paystack.php.
 * Migration: db/migrations/add_paystack.sql. Test: php tests/paystack_logic.php.
 *
 * Rules that are load-bearing:
 *  - A link is paid to ONE company — the one owed the money: a booking's property
 *    company, a till's outlet company, or the company staff pick for anything else.
 *  - The amount is decided by the server when the link is made (a POS sale is
 *    priced by the real sale path in a rolled-back transaction) and never changes.
 *  - Paid = Paystack's verify says success for the exact amount + currency
 *    (pay_link_verify_matches()). pay_link_mark_paid() is the ONLY writer of a
 *    paid link and is idempotent under a row lock, so the guest's return, the till's
 *    poll and the scheduler can all check at once without recording twice.
 *  - When paid, the money is recorded where it belongs: an acct_payments receipt
 *    (method 'paystack', into the company's Paystack account) applied to the
 *    booking's invoices; a POS link completes its sale through pos_complete_sale(),
 *    which records the receipt itself. If accounting isn't live, the link still
 *    shows Paid and says so in its note — never a silent loss.
 *  - A paid link is never undone here; a refund happens in Paystack + the folio.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/companies.php';
require_once __DIR__ . '/paystack.php';

const PAY_LINK_PURPOSES = ['bill' => 'Room bill', 'activity' => 'Activity / service', 'deposit' => 'Deposit', 'other' => 'Other amount', 'pos' => 'Till sale'];
const PAY_LINK_EXPIRY   = [1 => 'In 1 hour', 24 => 'In 24 hours', 48 => 'In 48 hours', 168 => 'In 7 days'];
const PAY_LINK_STATUSES = ['waiting' => 'Waiting', 'paid' => 'Paid', 'expired' => 'Expired', 'cancelled' => 'Cancelled'];
const PAY_LINK_POS_HOURS = 2;   // a till link lives long enough for a guest to pay at the counter

class PayLinkRefusal extends RuntimeException {}

// ── Pure helpers ────────────────────────────────────────────────────────────

function pay_link_number(int $id): string { return 'PL-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT); }

function pay_link_url(string $token): string { return site_url('/pay?l=' . rawurlencode($token)); }

function pay_link_cents(mixed $amount): int { return (int) round(((float)$amount) * 100, 0, PHP_ROUND_HALF_UP); }

function pay_link_money(mixed $amount, string $cur): string {
    $c = pay_link_cents($amount);
    return strtoupper($cur) . ' ' . number_format($c / 100, $c % 100 === 0 ? 0 : 2);
}

/** Waiting past its time reads as expired, before the scheduler gets to it. Pure. */
function pay_link_effective_status(array $l, ?int $now = null): string {
    $now ??= time();
    if ($l['status'] === 'waiting' && strtotime((string)$l['expires_at']) < $now) return 'expired';
    return (string)$l['status'];
}

/**
 * Does Paystack's verify answer prove THIS link was paid? Success, and exactly the
 * link's amount and currency — a guest who changed the amount is not "paid". Pure.
 */
function pay_link_verify_matches(array $l, array $v): bool {
    return ($v['status'] ?? '') === 'success'
        && (int)($v['amount'] ?? -1) === pay_link_cents($l['amount'])
        && strtoupper((string)($v['currency'] ?? '')) === strtoupper((string)$l['currency']);
}

/** The message sent with a link (WhatsApp / email). Pure. */
function pay_link_share_text(array $l, string $companyName): string {
    $name = trim((string)$l['customer_name']);
    $first = $name !== '' ? strtok($name, ' ') : '';
    return ($first !== '' ? "Hi {$first}, here" : 'Here') . " is your secure payment link for {$l['description']} ("
        . pay_link_money($l['amount'], (string)$l['currency']) . ") from {$companyName}:\n" . pay_link_url((string)$l['token'])
        . "\nYou can pay by M-Pesa or card.";
}

/** wa.me link: the guest's number (digits only, local 07… → 2547…) + the message. Pure. */
function pay_link_whatsapp_url(string $phone, string $text): string {
    $d = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($d, '0') && strlen($d) === 10) $d = '254' . substr($d, 1);
    return 'https://wa.me/' . $d . '?text=' . rawurlencode($text);
}

/** "Room bill — Garden Suite, 12–18 Oct 2026" / "Deposit — …". Pure. */
function pay_link_hold_description(array $h, string $purpose): string {
    $in = strtotime((string)$h['check_in']); $out = strtotime((string)$h['check_out']);
    $dates = date('Y-m', $in) === date('Y-m', $out) ? date('j', $in) . '–' . date('j M Y', $out) : date('j M', $in) . ' – ' . date('j M Y', $out);
    return ($purpose === 'deposit' ? 'Deposit' : 'Room bill') . ' — ' . trim((string)($h['room_name'] ?: $h['venue_name'] ?? 'Your stay')) . ', ' . $dates;
}

/** Validate the generator form. Returns [data, errors]. Pure (context passed in). */
function pay_link_clean(array $in, array $ctx): array {
    $e = []; $d = [];
    $purpose = (string)($in['purpose'] ?? '');
    if (!isset(PAY_LINK_PURPOSES[$purpose]) || $purpose === 'pos') $e['purpose'] = 'Pick what the link is for.';
    $d['purpose'] = $purpose;
    $raw = trim(str_replace(',', '', (string)($in['amount'] ?? '')));
    if (!is_numeric($raw) || (float)$raw <= 0 || (float)$raw > 99999999.99) $e['amount'] = 'Enter the amount to pay.';
    $d['amount'] = is_numeric($raw) ? pay_link_cents($raw) / 100.0 : 0.0;
    $d['currency'] = strtoupper(trim((string)($in['currency'] ?? '')));
    if (!in_array($d['currency'], $ctx['currencies'] ?? [], true)) $e['currency'] = 'Pick a currency this company takes on Paystack.';
    $d['description'] = mb_substr(trim((string)($in['description'] ?? '')), 0, 200);
    if ($d['description'] === '') $e['description'] = 'Say what it is for — the guest sees this.';
    $d['customer_name']  = mb_substr(trim((string)($in['customer_name'] ?? '')), 0, 160);
    $d['customer_email'] = mb_substr(trim((string)($in['customer_email'] ?? '')), 0, 160);
    $d['customer_phone'] = mb_substr(trim((string)($in['customer_phone'] ?? '')), 0, 40);
    if ($d['customer_email'] !== '' && !filter_var($d['customer_email'], FILTER_VALIDATE_EMAIL)) $e['customer_email'] = 'That email doesn’t look right.';
    if ($d['customer_phone'] !== '' && !preg_match('/^\+?[0-9 ()-]{7,20}$/', $d['customer_phone'])) $e['customer_phone'] = 'That phone number doesn’t look right.';
    $hours = (int)($in['expires_in'] ?? 48);
    $d['hours'] = isset(PAY_LINK_EXPIRY[$hours]) ? $hours : 48;
    $d['hold_id'] = (int)($ctx['hold_id'] ?? 0) ?: null;
    if (in_array($purpose, ['bill', 'deposit'], true) && !$d['hold_id']) $e['hold'] = 'Find the booking first.';
    $d['tour_id'] = $purpose === 'activity' ? ((int)($in['tour_id'] ?? 0) ?: null) : null;
    return [$d, $e];
}

// ── Who ─────────────────────────────────────────────────────────────────────

/** Who makes payment links: the people who take payments (acct_can_take_payments()'s audience). */
function pay_links_allowed(): bool {
    return is_owner() || is_manager() || is_reception() || is_frontdesk_staff();
}

/** Gate for admin/payments.php — plus the owner's "Access by role" grant. */
function require_pay_links(): void {
    require_login();
    if (!pay_links_allowed() && !access_page_granted()) {
        $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => 'Payments aren’t available for your account.'];
        header('Location: ' . admin_home_url()); exit;
    }
}

/** Cancel: who made it, the owner, or a manager. */
function pay_link_can_cancel(array $l): bool {
    return is_owner() || is_manager() || (int)($l['created_by'] ?? 0) === (int)($_SESSION['admin_id'] ?? -1);
}

// ── Reads ───────────────────────────────────────────────────────────────────

function pay_link_fetch(int $id): array|false {
    if (!paystack_supported()) return false;
    return db_query('SELECT l.*, c.name AS company_name, c.legal_name AS company_legal_name, c.email AS company_email, c.logo_key AS company_logo
                       FROM pay_links l JOIN companies c ON c.id = l.company_id WHERE l.id = :id', [':id' => $id])->fetch();
}

function pay_link_by_token(string $token): array|false {
    if (!paystack_supported() || !preg_match('/^[a-f0-9]{32}$/', $token)) return false;
    $id = db_query('SELECT id FROM pay_links WHERE token = :t', [':t' => $token])->fetchColumn();
    return $id ? pay_link_fetch((int)$id) : false;
}

/** A booking for a link: guest, property, company. By id or its booking code. */
function pay_link_find_hold(string $q): array|false {
    $q = trim($q);
    if ($q === '') return false;
    $where = ctype_digit($q) ? 'h.id = :q' : 'UPPER(h.access_code) = UPPER(:q)';
    // holds keep no phone: it comes from the enquiry the booking was made from (if any).
    $h = db_query("SELECT h.id, h.guest_name, h.guest_email, COALESCE(s.guest_phone, '') AS guest_phone, h.access_code, h.check_in, h.check_out, h.status,
                          r.name AS room_name, r.venue_id, v.name AS venue_name, v.company_id
                     FROM holds h JOIN units u ON u.id = h.unit_id JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u') . "
                     LEFT JOIN venues v ON v.id = r.venue_id LEFT JOIN submissions s ON s.id = h.submission_id WHERE {$where}", [':q' => ctype_digit($q) ? (int)$q : $q])->fetch();
    return $h ?: false;
}

/** What a booking still owes, per currency (cents) — from the folio when invoicing is live. */
function pay_link_hold_due(int $holdId): array {
    if (!function_exists('acct_folio') || !acct_supported()) return [];
    try {
        $f = acct_folio($holdId);
        if (!$f['ctx']['live']) return [];
        $out = [];
        foreach ($f['by_cur'] as $cur => $b) if ($b['due'] > 0) $out[$cur] = (int)$b['due'];
        return $out;
    } catch (Throwable $e) { return []; }
}

/** Companies this account may make links for: null = all (owner). */
function pay_link_company_scope(): ?array {
    if (is_owner()) return null;
    $vids = admin_venue_ids() ?? [];
    if (!$vids) return [];
    $ph = []; $p = [];
    foreach (array_values($vids) as $i => $v) { $ph[] = ":v{$i}"; $p[":v{$i}"] = (int)$v; }
    $in = implode(',', $ph);
    $sql = "SELECT company_id FROM venues WHERE id IN ({$in}) AND company_id IS NOT NULL";
    if (companies_outlets_supported()) $sql .= " UNION SELECT company_id FROM pos_outlets WHERE venue_id IN ({$in}) AND company_id IS NOT NULL";
    return array_map('intval', db_query($sql, $p)->fetchAll(PDO::FETCH_COLUMN));
}

/** Companies (rows + their config) that can take Paystack payments for this account. */
function pay_link_ready_companies(): array {
    if (!paystack_supported()) return [];
    $scope = pay_link_company_scope();
    $out = [];
    foreach (db_query('SELECT * FROM companies WHERE is_active ORDER BY LOWER(name)')->fetchAll() as $c) {
        if ($scope !== null && !in_array((int)$c['id'], $scope, true)) continue;
        $cfg = paystack_company_config($c);
        $out[(int)$c['id']] = ['row' => $c, 'cfg' => $cfg];
    }
    return $out;
}

/** The list, newest first. $companyIds null = every company. */
function pay_link_list(array $f, ?array $companyIds, int $limit = 300): array {
    if (!paystack_supported() || $companyIds === []) return [];
    $w = ['1=1']; $p = [];
    if ($companyIds !== null) {
        $ph = [];
        foreach (array_values($companyIds) as $i => $c) { $ph[] = ":c{$i}"; $p[":c{$i}"] = (int)$c; }
        $w[] = 'l.company_id IN (' . implode(',', $ph) . ')';
    }
    if (!empty($f['hold_id'])) { $w[] = 'l.hold_id = :h'; $p[':h'] = (int)$f['hold_id']; }
    $st = (string)($f['status'] ?? '');
    if ($st === 'waiting')  $w[] = "l.status = 'waiting' AND l.expires_at >= now()";
    elseif ($st === 'expired') $w[] = "(l.status = 'expired' OR (l.status = 'waiting' AND l.expires_at < now()))";
    elseif (isset(PAY_LINK_STATUSES[$st])) { $w[] = 'l.status = :st'; $p[':st'] = $st; }
    $q = trim((string)($f['q'] ?? ''));
    if ($q !== '') { $w[] = '(l.description ILIKE :q OR l.customer_name ILIKE :q OR l.reference ILIKE :q OR l.customer_email ILIKE :q)'; $p[':q'] = '%' . $q . '%'; }
    return db_query('SELECT l.*, c.name AS company_name, a.name AS created_by_name
                       FROM pay_links l JOIN companies c ON c.id = l.company_id LEFT JOIN admin_users a ON a.id = l.created_by
                      WHERE ' . implode(' AND ', $w) . ' ORDER BY l.created_at DESC, l.id DESC LIMIT ' . max(1, $limit), $p)->fetchAll();
}

/** Tiles: waiting count, paid this month per currency, expired count. Pure. */
function pay_link_summary(array $links, ?int $now = null): array {
    $now ??= time();
    $month = date('Y-m', $now);
    $s = ['waiting' => 0, 'expired' => 0, 'paid_month' => []];
    foreach ($links as $l) {
        $st = pay_link_effective_status($l, $now);
        if ($st === 'waiting') $s['waiting']++;
        if ($st === 'expired') $s['expired']++;
        if ($st === 'paid' && !empty($l['paid_at']) && date('Y-m', strtotime((string)$l['paid_at'])) === $month) {
            $c = strtoupper((string)$l['currency']);
            $s['paid_month'][$c] = ($s['paid_month'][$c] ?? 0) + pay_link_cents($l['amount']);
        }
    }
    ksort($s['paid_month']);
    return $s;
}

// ── Writes ──────────────────────────────────────────────────────────────────

/** Insert a link. $d from pay_link_clean() (+ company_id, outlet/terminal/pos_request for the till). */
function pay_link_insert(array $d, int $companyId, string $mode, ?int $userId): int {
    $token = bin2hex(random_bytes(16));
    $ref   = 'TSP-' . strtoupper(bin2hex(random_bytes(6)));
    db_query('INSERT INTO pay_links (token, reference, company_id, purpose, hold_id, tour_id, description, amount, currency,
                                     customer_name, customer_email, customer_phone, mode, expires_at, outlet_id, terminal_id, pos_request, created_by)
              VALUES (:t, :r, :c, :p, :h, :tour, :d, :a, :cur, :n, :e, :ph, :m, now() + make_interval(hours => :hrs), :o, :term, :req, :u)', [
        ':t' => $token, ':r' => $ref, ':c' => $companyId, ':p' => $d['purpose'], ':h' => $d['hold_id'] ?? null, ':tour' => $d['tour_id'] ?? null,
        ':d' => $d['description'], ':a' => $d['amount'], ':cur' => $d['currency'], ':n' => $d['customer_name'] ?? '',
        ':e' => $d['customer_email'] ?? '', ':ph' => $d['customer_phone'] ?? '', ':m' => $mode, ':hrs' => (int)$d['hours'],
        ':o' => $d['outlet_id'] ?? null, ':term' => $d['terminal_id'] ?? null,
        ':req' => isset($d['pos_request']) ? json_encode($d['pos_request']) : null, ':u' => $userId]);
    return (int) db()->lastInsertId();
}

/**
 * Make a link from the generator (Payments tab / Bill tab). The company is the
 * booking's for a bill or deposit, else the one picked; it must be ready for
 * Paystack in that currency and inside the account's scope.
 */
function pay_link_create(array $in, ?int $userId): int {
    if (!paystack_supported()) throw new PayLinkRefusal('Run add_paystack.sql first.');
    $hold = null;
    if (in_array((string)($in['purpose'] ?? ''), ['bill', 'deposit'], true)) {
        $hold = pay_link_find_hold((string)($in['hold'] ?? ''));
        if (!$hold) throw new PayLinkRefusal('That booking could not be found — use its number or booking code.');
        if (!staff_can_hold((int)$hold['id'])) throw new PayLinkRefusal('That booking is not at one of your properties.');
        if (!$hold['company_id']) throw new PayLinkRefusal(($hold['venue_name'] ?: 'This property') . ' has no company yet — the owner sets it in Accounting → Companies.');
        $companyId = (int)$hold['company_id'];
        // Blank amount on a bill = what the folio says is still due in that currency.
        $due = pay_link_hold_due((int)$hold['id']);
        $cur = strtoupper((string)($in['currency'] ?? ''));
        if (trim((string)($in['amount'] ?? '')) === '' && isset($due[$cur])) $in['amount'] = (string)($due[$cur] / 100);
        if (trim((string)($in['description'] ?? '')) === '') $in['description'] = pay_link_hold_description($hold, (string)$in['purpose']);
    } else {
        $companyId = (int)($in['company_id'] ?? 0);
    }
    $ready = pay_link_ready_companies();
    if (!isset($ready[$companyId])) throw new PayLinkRefusal('Pick a company you can take payments for.');
    $cfg = $ready[$companyId]['cfg'];
    if (!$cfg['ready']) throw new PayLinkRefusal($cfg['problem']);
    [$d, $err] = pay_link_clean($in + ['description' => ''], ['currencies' => array_keys($cfg['accounts']), 'hold_id' => $hold['id'] ?? null]);
    if ($err) throw new PayLinkRefusal(reset($err));
    if ($hold) {
        $d['customer_name']  = $d['customer_name']  ?: (string)$hold['guest_name'];
        $d['customer_email'] = $d['customer_email'] ?: (string)($hold['guest_email'] ?? '');
        $d['customer_phone'] = $d['customer_phone'] ?: (string)($hold['guest_phone'] ?? '');
    }
    $id = pay_link_insert($d, $companyId, $cfg['mode'], $userId);
    try { audit_log('paylink.create', 'pay_link', $id, $d['description'] . ' · ' . pay_link_money($d['amount'], $d['currency'])); } catch (Throwable $e) {}
    return $id;
}

/**
 * A till sale paid by Paystack: price it through the REAL sale path inside a
 * transaction that is rolled back (so the amount is exactly what the sale will be),
 * then store the request on a link. The same till key returns the same link.
 */
function pay_link_create_pos(array $req, int $userId, ?int $terminalId): array {
    if (!paystack_supported()) throw new PayLinkRefusal('Paystack is not set up yet.');
    require_once __DIR__ . '/pos.php';
    $uuid = trim((string)($req['client_uuid'] ?? ''));
    if (!pos_valid_uuid($uuid)) throw new PayLinkRefusal('Missing sale key — reload the till and try again.');
    $have = db_query("SELECT id, status FROM pay_links WHERE purpose = 'pos' AND pos_request->>'client_uuid' = :u ORDER BY id DESC LIMIT 1", [':u' => $uuid])->fetch();
    if ($have && in_array($have['status'], ['waiting', 'paid'], true)) return pay_link_fetch((int)$have['id']);   // a retry: the same link
    if ($have) throw new PayLinkRefusal('That payment link was cancelled or has expired — review the sale again.');
    if (pos_sale_by_uuid($uuid)) throw new PayLinkRefusal('That sale is already complete.');

    $outletId = (int)($req['outlet_id'] ?? 0);
    $coId = company_for_outlet($outletId);
    $co = $coId ? company_fetch($coId) : false;
    if (!$co) throw new PayLinkRefusal('This outlet has no company — the owner sets it in Accounting → Companies.');
    $cfg = paystack_company_config($co);
    if (!$cfg['ready']) throw new PayLinkRefusal($cfg['problem']);

    $req['payment_method'] = 'paystack';
    unset($req['signature'], $req['cash_tendered'], $req['offline_sold_at']);
    // A dry run: nothing is sold until Paystack confirms. Inside a caller's
    // transaction (the tests) it is a savepoint, so only the dry run is undone.
    $pdo = db();
    $nested = $pdo->inTransaction();
    $nested ? $pdo->exec('SAVEPOINT pay_link_dry') : $pdo->beginTransaction();
    $undo = fn() => $nested ? $pdo->exec('ROLLBACK TO SAVEPOINT pay_link_dry') : $pdo->rollBack();
    try {
        $sid  = pos_complete_sale_tx($req, $uuid, $userId, $terminalId, true);
        $sale = db_query('SELECT total, currency, customer_name, reference FROM pos_sales WHERE id = :s', [':s' => $sid])->fetch();
        $names = db_query('SELECT qty, name FROM pos_sale_lines WHERE sale_id = :s ORDER BY id', [':s' => $sid])->fetchAll();
    } catch (PosRefusal $e) {
        $undo();
        throw new PayLinkRefusal($e->getMessage());
    } catch (Throwable $e) {
        $undo();
        throw $e;
    }
    $undo();

    $cur = strtoupper((string)$sale['currency']);
    if (!isset($cfg['accounts'][$cur])) throw new PayLinkRefusal("{$co['name']} has no Paystack account for {$cur} — the owner sets it on the company’s Paystack tab.");
    $outlet = db_query('SELECT name FROM pos_outlets WHERE id = :o', [':o' => $outletId])->fetchColumn();
    $items = implode(', ', array_map(fn($n) => ((int)$n['qty'] > 1 ? $n['qty'] . ' × ' : '') . $n['name'], $names));
    $cust = (array)($req['customer'] ?? []);
    $d = [
        'purpose' => 'pos', 'hold_id' => ($cust['type'] ?? '') === 'inhouse' ? ((int)($cust['hold_id'] ?? 0) ?: null) : null, 'tour_id' => null,
        'description' => mb_substr(($outlet ?: 'Till') . ': ' . $items, 0, 200), 'amount' => (float)$sale['total'], 'currency' => $cur,
        'customer_name' => $sale['customer_name'] === 'Walk-in' ? '' : (string)$sale['customer_name'],
        'customer_email' => '', 'customer_phone' => (string)($cust['phone'] ?? ''), 'hours' => PAY_LINK_POS_HOURS,
        'outlet_id' => $outletId, 'terminal_id' => $terminalId, 'pos_request' => $req,
    ];
    $id = pay_link_insert($d, (int)$co['id'], $cfg['mode'], $userId);
    try { audit_log('paylink.create', 'pay_link', $id, $d['description']); } catch (Throwable $e) {}
    return pay_link_fetch($id);
}

function pay_link_cancel(int $id, ?int $userId): void {
    $n = db_query("UPDATE pay_links SET status = 'cancelled', cancelled_by = :u WHERE id = :id AND status = 'waiting'", [':id' => $id, ':u' => $userId])->rowCount();
    if (!$n) throw new PayLinkRefusal('Only a waiting link can be cancelled.');
    try { audit_log('paylink.cancel', 'pay_link', $id, ''); } catch (Throwable $e) {}
}

/**
 * The guest pressed Pay. Practice → our own pretend page; otherwise a Paystack
 * checkout (made once, then reused). Returns the URL to send the guest to.
 */
function pay_link_start(array $l): string {
    if (pay_link_effective_status($l) !== 'waiting') throw new PayLinkRefusal('This link can no longer be paid.');
    if ($l['mode'] === 'practice') {
        if (!paystack_practice_allowed()) throw new PayLinkRefusal('This is a practice link — it can’t be paid on the live site.');
        return '';
    }
    if ((string)$l['paystack_url'] !== '') return (string)$l['paystack_url'];
    $co  = company_fetch((int)$l['company_id']);
    $cfg = paystack_company_config($co);
    if ($cfg['mode'] !== $l['mode'] || $cfg['secret'] === '') throw new PayLinkRefusal('Online payment is not available right now — please contact us.');
    $email = (string)$l['customer_email'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $email = filter_var((string)($co['email'] ?? ''), FILTER_VALIDATE_EMAIL) ? (string)$co['email'] : 'payments@tribalsand.com';
    $r = paystack_initialize($cfg['secret'], [
        'email' => $email, 'amount' => pay_link_cents($l['amount']), 'currency' => strtoupper((string)$l['currency']),
        'reference' => (string)$l['reference'], 'callback_url' => pay_link_url((string)$l['token']) . '&back=1',
        'channels' => $cfg['channels'],
        'metadata' => ['pay_link' => pay_link_number((int)$l['id']), 'custom_fields' => [
            ['display_name' => 'For', 'variable_name' => 'for', 'value' => (string)$l['description']]]],
    ]);
    if (!$r['ok']) {
        // The reference was already started (e.g. the URL was lost): ask Paystack where it stands.
        error_log('[paystack] initialize failed for ' . $l['reference'] . ': ' . $r['error']);
        throw new PayLinkRefusal('We couldn’t open the payment page — please try again in a moment.');
    }
    db_query('UPDATE pay_links SET paystack_url = :u, started_at = COALESCE(started_at, now()) WHERE id = :id', [':u' => $r['url'], ':id' => (int)$l['id']]);
    return $r['url'];
}

/**
 * Ask Paystack about a waiting link (rate-limited per link unless $force) and act:
 * paid → pay_link_mark_paid(); past its time and not paid → expired. Returns the
 * fresh link row.
 */
function pay_link_check(int $id, bool $force = false): array|false {
    $l = pay_link_fetch($id);
    if (!$l) return false;
    $askable = in_array($l['status'], ['waiting', 'expired'], true) && in_array($l['mode'], ['test', 'live'], true) && !empty($l['started_at']);
    $recent  = !empty($l['last_checked_at']) && strtotime((string)$l['last_checked_at']) > time() - PAYSTACK_CHECK_GAP;
    if ($askable && ($force || !$recent)) {
        db_query('UPDATE pay_links SET last_checked_at = now() WHERE id = :id', [':id' => $id]);
        $cfg = paystack_company_config(company_fetch((int)$l['company_id']));
        $secret = $cfg['mode'] === $l['mode'] ? $cfg['secret'] : '';
        if ($secret === '' && $l['mode'] !== $cfg['mode']) {
            // The company switched mode after the link was made: use that mode's key.
            $secret = paystack_decrypt((string)(company_fetch((int)$l['company_id'])['paystack_' . $l['mode'] . '_secret_enc'] ?? ''));
        }
        if ($secret !== '') {
            $v = paystack_verify($secret, (string)$l['reference']);
            if ($v['ok'] && pay_link_verify_matches($l, $v)) {
                pay_link_mark_paid($id, ['channel' => $v['channel'], 'txn_id' => $v['id'], 'paid_at' => $v['paid_at']]);
                return pay_link_fetch($id);
            }
            if ($v['ok'] && $v['status'] === 'success') {
                db_query('UPDATE pay_links SET note = :n WHERE id = :id', [':id' => $id,
                    ':n' => 'Paystack shows a payment of ' . strtoupper($v['currency']) . ' ' . number_format($v['amount'] / 100, 2) . ', which does not match this link — check it in Paystack.']);
            }
        }
    }
    if ($l['status'] === 'waiting' && strtotime((string)$l['expires_at']) < time()) {
        db_query("UPDATE pay_links SET status = 'expired' WHERE id = :id AND status = 'waiting'", [':id' => $id]);
    }
    return pay_link_fetch($id);
}

/**
 * THE writer of a paid link. Locks the row; a link already paid returns false (so
 * concurrent checks record once). Records the money, then — for a till link —
 * completes the sale after the commit. $info: channel, txn_id, paid_at.
 */
function pay_link_mark_paid(int $id, array $info): bool {
    $done = company_tx(function () use ($id, $info): ?array {
        $l = db_query('SELECT * FROM pay_links WHERE id = :id FOR UPDATE', [':id' => $id])->fetch();
        if (!$l || $l['status'] === 'paid') return null;
        $note = $l['status'] === 'waiting' ? '' : 'Paid after it was ' . $l['status'] . '. ';
        $paidAt = !empty($info['paid_at']) && strtotime((string)$info['paid_at']) ? date('Y-m-d H:i:sP', strtotime((string)$info['paid_at'])) : date('Y-m-d H:i:sP');
        db_query("UPDATE pay_links SET status = 'paid', paid_at = :at, paid_channel = :ch, paystack_txn_id = :tx WHERE id = :id",
            [':id' => $id, ':at' => $paidAt, ':ch' => mb_substr((string)($info['channel'] ?? ''), 0, 30), ':tx' => $info['txn_id'] ?? null]);
        if ($l['purpose'] !== 'pos') {
            [$paymentId, $msg] = pay_link_record_payment($l, $paidAt);
            db_query('UPDATE pay_links SET payment_id = :p, note = :n WHERE id = :id', [':p' => $paymentId, ':n' => trim($note . $msg), ':id' => $id]);
        }
        return $l;
    });
    if (!$done) return false;
    try { audit_log('paylink.paid', 'pay_link', $id, (string)($info['channel'] ?? '')); } catch (Throwable $e) {}
    if ($done['purpose'] === 'pos') pay_link_complete_pos($id);
    return true;
}

/**
 * Inside the paid transaction: the acct_payments receipt for a non-till link.
 * Returns [payment id|null, note for staff].
 */
function pay_link_record_payment(array $l, string $paidAt): array {
    if (!function_exists('acct_supported')) require_once __DIR__ . '/acct.php';
    if (!acct_supported()) return [null, 'Invoicing is not set up, so it is recorded on this link only.'];
    $co = company_fetch((int)$l['company_id']);
    $cur = strtoupper((string)$l['currency']);
    $accId = (int)($co['paystack_account_' . strtolower($cur)] ?? 0);
    $acc = $accId ? db_query('SELECT * FROM company_accounts WHERE id = :a AND company_id = :c', [':a' => $accId, ':c' => $co['id']])->fetch() : false;
    if (!$acc) return [null, "No Paystack {$cur} account is set for {$co['name']}, so it is recorded on this link only."];
    $holdId = $l['hold_id'] ? (int)$l['hold_id'] : null;
    if ($holdId) {
        $ctx = acct_hold_context($holdId);
        if (!$ctx['live']) return [null, 'Recorded on this link only — ' . lcfirst(rtrim($ctx['reason'], '.')) . '.'];
        if ((int)$ctx['company']['id'] !== (int)$co['id']) return [null, 'The booking now belongs to another company — record it on the Bill tab by hand.'];
    } elseif (!acct_company_live_on($co, date('Y-m-d'))) {
        return [null, "Invoicing is not live for {$co['name']}, so it is recorded on this link only."];
    }
    $fx = acct_fx_rate((string)$co['home_currency'], $cur) ?? 1.0;
    db_query("INSERT INTO acct_payments (company_id, account_id, kind, method, amount, currency, fx_to_home, reference, payer_name, reason, hold_id, received_at)
              VALUES (:c, :a, 'receipt', 'paystack', :amt, :cur, :fx, :ref, :payer, :why, :h, :at)",
        [':c' => $co['id'], ':a' => $acc['id'], ':amt' => pay_link_cents($l['amount']) / 100, ':cur' => $cur, ':fx' => $fx,
         ':ref' => (string)$l['reference'], ':payer' => mb_substr((string)$l['customer_name'], 0, 200),
         ':why' => pay_link_number((int)$l['id']) . ' · ' . $l['description'], ':h' => $holdId, ':at' => $paidAt]);
    $pid = (int) db()->lastInsertId();
    if ($holdId) acct_auto_allocate($holdId);
    return [$pid, $holdId ? 'Recorded as a payment on the booking’s bill.' : 'Recorded as a payment for ' . $co['name'] . '.'];
}

/** After the commit: complete the till sale the link was made for. */
function pay_link_complete_pos(int $id): void {
    require_once __DIR__ . '/pos.php';
    $l = pay_link_fetch($id);
    if (!$l || $l['pos_sale_id'] || !$l['pos_request']) return;
    $req = json_decode((string)$l['pos_request'], true) ?: [];
    $req['payment_method'] = 'paystack';
    $req['payment_ref'] = (string)$l['reference'];
    $r = pos_complete_sale($req, (int)$l['created_by'], $l['terminal_id'] ? (int)$l['terminal_id'] : null, true);
    if ($r['ok']) {
        db_query('UPDATE pay_links SET pos_sale_id = :s, note = :n WHERE id = :id', [':s' => (int)$r['sale']['id'], ':n' => 'Sale ' . $r['sale']['reference'] . ' completed.', ':id' => $id]);
    } else {
        db_query('UPDATE pay_links SET note = :n WHERE id = :id', [':id' => $id,
            ':n' => 'Paid, but the sale could not be completed: ' . $r['error'] . ' Ring it up again with "Other" and reference ' . $l['reference'] . '.']);
    }
}

/** Scheduler: ask Paystack about every started waiting link; expire old ones. Returns [checked, paid, expired]. */
function pay_link_check_due(int $max = 200): array {
    if (!paystack_supported()) return [0, 0, 0];
    $checked = $paid = 0;
    $ids = db_query("SELECT id FROM pay_links
                      WHERE status IN ('waiting','expired') AND mode IN ('test','live') AND started_at IS NOT NULL
                        AND created_at > now() - interval '14 days'
                        AND (last_checked_at IS NULL OR last_checked_at < now() - interval '4 minutes')
                      ORDER BY id LIMIT " . max(1, $max))->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $checked++;
        try { $l = pay_link_check((int)$id, true); if ($l && $l['status'] === 'paid') $paid++; }
        catch (Throwable $e) { error_log('[paystack] check ' . $id . ' failed: ' . $e->getMessage()); }
    }
    $expired = db_query("UPDATE pay_links SET status = 'expired' WHERE status = 'waiting' AND expires_at < now()
                           AND (started_at IS NULL OR mode = 'practice')")->rowCount();
    return [$checked, $paid, $expired];
}
