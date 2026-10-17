<?php
declare(strict_types=1);
// Paystack payment links. Run: php tests/paystack_logic.php
// Pure rules + the Paystack client (HTTP stubbed) always run. The DB block runs
// inside ONE transaction that is rolled back, and SKIPs when no database is
// reachable or add_paystack.sql is missing.
$_SERVER['PAYMENT_KEYS_SECRET'] = 'test-only-payment-keys-secret';

// Paystack is never called for real: every HTTP call is answered from $GLOBALS['ps_stub'].
$GLOBALS['ps_stub'] = [];
$GLOBALS['ps_calls'] = [];
function paystack_http(string $secret, string $method, string $path, ?array $body = null): array {
    $GLOBALS['ps_calls'][] = [$method, $path, $body];
    foreach ($GLOBALS['ps_stub'] as $prefix => $reply) if (str_starts_with($path, $prefix)) return $reply;
    return [0, null, 'no stub'];
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/pay-links.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Practice mode only off the live site ────────────────────────────────────
check('practice: refused on the live host', !paystack_practice_allowed(['APP_URL' => 'https://tribalsand.com']));
check('practice: refused on www too', !paystack_practice_allowed(['APP_URL' => 'https://www.tribalsand.com/']));
check('practice: allowed on localhost / dev', paystack_practice_allowed(['APP_URL' => 'http://localhost:8765']) && paystack_practice_allowed(['APP_URL' => 'https://dev.tribalsand.com']));
check('practice: PAYSTACK_PRACTICE=off wins on dev', !paystack_practice_allowed(['APP_URL' => 'http://localhost', 'PAYSTACK_PRACTICE' => 'off']));
check('practice: PAYSTACK_PRACTICE=on wins on live', paystack_practice_allowed(['APP_URL' => 'https://tribalsand.com', 'PAYSTACK_PRACTICE' => 'on']));

// ── Keys ────────────────────────────────────────────────────────────────────
check('keys: a test secret key is accepted', paystack_key_problem('secret', 'test', 'sk_test_' . str_repeat('a1B2', 10)) === null);
check('keys: blank is allowed (not set yet)', paystack_key_problem('public', 'live', '') === null);
check('keys: a live key in the test box is refused', paystack_key_problem('secret', 'test', 'sk_live_' . str_repeat('a', 40)) !== null);
check('keys: a public key in the secret box is refused', paystack_key_problem('secret', 'live', 'pk_live_' . str_repeat('a', 40)) !== null);
check('keys: junk characters refused', paystack_key_problem('public', 'test', 'pk_test_abc def ghi jkl mno') !== null);
$sec = 'sk_live_' . str_repeat('9f8e', 10);
$enc = paystack_encrypt($sec);
check('keys: stored encrypted (not the key itself)', str_starts_with($enc, 'v1:') && !str_contains($enc, $sec));
check('keys: decrypts back', paystack_decrypt($enc) === $sec);
check('keys: two encryptions differ (random IV)', paystack_encrypt($sec) !== $enc);
check('keys: a tampered value reads as no key', paystack_decrypt(substr($enc, 0, -4) . 'AAAA') === '');
check('keys: empty stays empty', paystack_encrypt('') === '' && paystack_decrypt('') === '');
check('keys: masked for display', paystack_mask($sec) === 'sk_live_…9f8e');

// ── Was this link paid? ─────────────────────────────────────────────────────
$link = ['amount' => '9500.00', 'currency' => 'KES', 'status' => 'waiting', 'expires_at' => date('Y-m-d H:i:s', time() + 3600)];
check('verify: success + exact amount + currency = paid', pay_link_verify_matches($link, ['status' => 'success', 'amount' => 950000, 'currency' => 'KES']));
check('verify: a different amount is not paid', !pay_link_verify_matches($link, ['status' => 'success', 'amount' => 95000, 'currency' => 'KES']));
check('verify: a different currency is not paid', !pay_link_verify_matches($link, ['status' => 'success', 'amount' => 950000, 'currency' => 'USD']));
check('verify: abandoned / failed is not paid', !pay_link_verify_matches($link, ['status' => 'abandoned', 'amount' => 950000, 'currency' => 'KES']));
check('verify: cents are exact (USD 80.10)', pay_link_verify_matches(['amount' => '80.10', 'currency' => 'USD'], ['status' => 'success', 'amount' => 8010, 'currency' => 'usd']));

check('status: waiting before its time', pay_link_effective_status($link) === 'waiting');
check('status: waiting past its time reads expired', pay_link_effective_status(['status' => 'waiting', 'expires_at' => '2020-01-01 00:00:00']) === 'expired');
check('status: paid stays paid after its time', pay_link_effective_status(['status' => 'paid', 'expires_at' => '2020-01-01 00:00:00']) === 'paid');

// ── Display + sharing ───────────────────────────────────────────────────────
check('money: whole amounts without decimals', pay_link_money('12500.00', 'kes') === 'KES 12,500');
check('money: cents kept', pay_link_money('80.5', 'USD') === 'USD 80.50');
check('number: PL + 6 digits', pay_link_number(124) === 'PL-000124');
$share = pay_link_share_text(['customer_name' => 'Anna Rossi', 'description' => 'Room bill', 'amount' => '940', 'currency' => 'USD', 'token' => str_repeat('a', 32)], 'Zuri Ltd');
check('share: greets by first name, says what, how much, who, and the link', str_starts_with($share, 'Hi Anna, here') && str_contains($share, 'USD 940') && str_contains($share, 'Zuri Ltd') && str_contains($share, '/pay?l=' . str_repeat('a', 32)));
check('share: no name = no greeting', str_starts_with(pay_link_share_text(['customer_name' => '', 'description' => 'X', 'amount' => '1', 'currency' => 'KES', 'token' => 'x'], 'Co'), 'Here is'));
check('whatsapp: local 07… becomes 2547…', str_starts_with(pay_link_whatsapp_url('0712 345 678', 'hi'), 'https://wa.me/254712345678?text=hi'));
check('whatsapp: international kept', str_starts_with(pay_link_whatsapp_url('+49 151 2345678', 'x'), 'https://wa.me/491512345678?'));
check('hold: description names the room and dates', pay_link_hold_description(['check_in' => '2026-10-12', 'check_out' => '2026-10-18', 'room_name' => 'Garden Suite', 'venue_name' => 'Zuri'], 'bill') === 'Room bill — Garden Suite, 12–18 Oct 2026');
check('hold: deposit across months', pay_link_hold_description(['check_in' => '2026-10-30', 'check_out' => '2026-11-02', 'room_name' => '', 'venue_name' => 'Zuri'], 'deposit') === 'Deposit — Zuri, 30 Oct – 2 Nov 2026');

// ── Generator form ──────────────────────────────────────────────────────────
[$d, $e] = pay_link_clean(['purpose' => 'activity', 'amount' => '9,500', 'currency' => 'kes', 'description' => ' Massage ', 'customer_phone' => '+254 712 345678', 'expires_in' => '24'], ['currencies' => ['KES']]);
check('form: a valid activity link passes', !$e && $d['amount'] === 9500.0 && $d['currency'] === 'KES' && $d['description'] === 'Massage' && $d['hours'] === 24);
[, $e] = pay_link_clean(['purpose' => 'other', 'amount' => '0', 'currency' => 'KES', 'description' => 'X'], ['currencies' => ['KES']]);
check('form: zero amount refused', isset($e['amount']));
[, $e] = pay_link_clean(['purpose' => 'other', 'amount' => '10', 'currency' => 'USD', 'description' => 'X'], ['currencies' => ['KES']]);
check('form: a currency the company does not take is refused', isset($e['currency']));
[, $e] = pay_link_clean(['purpose' => 'bill', 'amount' => '10', 'currency' => 'KES', 'description' => 'X'], ['currencies' => ['KES']]);
check('form: a bill needs a booking', isset($e['hold']));
[, $e] = pay_link_clean(['purpose' => 'pos', 'amount' => '10', 'currency' => 'KES', 'description' => 'X'], ['currencies' => ['KES']]);
check('form: till links are not made from the generator', isset($e['purpose']));
[, $e] = pay_link_clean(['purpose' => 'other', 'amount' => '10', 'currency' => 'KES', 'description' => '', 'customer_email' => 'nope'], ['currencies' => ['KES']]);
check('form: description required, bad email refused', isset($e['description'], $e['customer_email']));
[$d] = pay_link_clean(['purpose' => 'other', 'amount' => '10', 'currency' => 'KES', 'description' => 'X', 'expires_in' => '999'], ['currencies' => ['KES']]);
check('form: unknown expiry falls back to 48 h', $d['hours'] === 48);

$s = pay_link_summary([
    ['status' => 'waiting', 'expires_at' => date('Y-m-d H:i:s', time() + 99), 'currency' => 'KES', 'amount' => '1', 'paid_at' => null],
    ['status' => 'waiting', 'expires_at' => '2020-01-01', 'currency' => 'KES', 'amount' => '1', 'paid_at' => null],
    ['status' => 'paid', 'expires_at' => '2020-01-01', 'currency' => 'KES', 'amount' => '100', 'paid_at' => date('Y-m-d H:i:s')],
    ['status' => 'paid', 'expires_at' => '2020-01-01', 'currency' => 'USD', 'amount' => '5.5', 'paid_at' => date('Y-m-d H:i:s')],
    ['status' => 'paid', 'expires_at' => '2020-01-01', 'currency' => 'KES', 'amount' => '7', 'paid_at' => '2020-01-01 10:00:00'],
]);
check('summary: waiting / expired counts, paid this month per currency (never summed across)', $s['waiting'] === 1 && $s['expired'] === 1 && $s['paid_month'] === ['KES' => 10000, 'USD' => 550]);

// ── Paystack client (stubbed HTTP) ──────────────────────────────────────────
$GLOBALS['ps_stub'] = ['/transaction/initialize' => [200, ['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc', 'access_code' => 'abc']], '']];
$r = paystack_initialize('sk_test_x', ['amount' => 100]);
check('client: initialize returns the checkout page', $r['ok'] && $r['url'] === 'https://checkout.paystack.com/abc');
check('client: it is a POST to /transaction/initialize', end($GLOBALS['ps_calls'])[0] === 'POST');
$GLOBALS['ps_stub'] = ['/transaction/verify/' => [200, ['status' => true, 'data' => ['status' => 'success', 'amount' => 950000, 'currency' => 'KES', 'channel' => 'mobile_money', 'id' => 42, 'paid_at' => '2026-10-17T10:00:00Z']], '']];
$v = paystack_verify('sk_test_x', 'TSP-ABC');
check('client: verify reads status, amount (subunits), channel', $v['ok'] && $v['status'] === 'success' && $v['amount'] === 950000 && $v['channel'] === 'mobile_money' && $v['id'] === 42);
check('client: reference is URL-encoded in the path', end($GLOBALS['ps_calls'])[1] === '/transaction/verify/TSP-ABC');
$GLOBALS['ps_stub'] = ['/transaction/verify/' => [400, ['status' => false, 'message' => 'Transaction reference not found'], '']];
$v = paystack_verify('sk_test_x', 'TSP-NEW');
check('client: a reference never opened is not_found (not an error)', $v['ok'] && $v['status'] === 'not_found');
$GLOBALS['ps_stub'] = ['/transaction' => [401, ['status' => false, 'message' => 'Invalid key'], '']];
check('client: a refused key says so', str_contains(paystack_test_connection('sk_test_bad')['error'], 'refused the key'));
$GLOBALS['ps_stub'] = [];
check('client: no connection is a clear error', !paystack_test_connection('sk_test_x')['ok']);
check('client: no key never calls Paystack', !paystack_call('', 'GET', '/x')['ok']);

// ── DB block ────────────────────────────────────────────────────────────────
try {
    db()->query('SELECT 1');
} catch (Throwable $e) {
    echo "\nSKIP  DB block (database unavailable: " . $e->getMessage() . ")\n";
    echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
    exit($failures ? 1 : 0);
}
if (!paystack_supported()) {
    echo "\nSKIP  DB block (add_paystack.sql not applied)\n";
    echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
    exit($failures ? 1 : 0);
}
$pdo = db();
$pdo->beginTransaction();
try {
    db_query("INSERT INTO companies (name, code, paystack_mode) VALUES ('Paystack Test Co', 'PSTX', 'practice')");
    $coId = (int) $pdo->lastInsertId();
    db_query("INSERT INTO company_accounts (company_id, label, kind, currency) VALUES (:c, 'Settlement KES', 'bank', 'KES')", [':c' => $coId]);
    $accId = (int) $pdo->lastInsertId();
    paystack_save_settings($coId, ['paystack_mode' => paystack_practice_allowed() ? 'practice' : 'off', 'paystack_account_kes' => (string)$accId, 'paystack_channels' => ['card', 'mobile_money']]);
    $co = company_fetch($coId);
    $cfg = paystack_company_config($co);
    check('db: settings saved — account per currency, channels', $cfg['accounts'] === ['KES' => $accId] && $cfg['channels'] === ['card', 'mobile_money']);
    $refused = false;
    try { paystack_save_settings($coId, ['paystack_mode' => 'live', 'paystack_account_kes' => (string)$accId, 'paystack_channels' => ['card']]); }
    catch (CompanyRefusal $e) { $refused = true; }
    check('db: Live without a secret key is refused', $refused);
    paystack_save_settings($coId, ['paystack_mode' => 'test', 'paystack_test_secret' => 'sk_test_' . str_repeat('ab12', 8), 'paystack_account_kes' => (string)$accId, 'paystack_channels' => ['card']]);
    $row = company_fetch($coId);
    check('db: the secret is stored encrypted and reads back', str_starts_with((string)$row['paystack_test_secret_enc'], 'v1:') && paystack_company_config($row)['secret'] === 'sk_test_' . str_repeat('ab12', 8));
    paystack_save_settings($coId, ['paystack_mode' => 'test', 'paystack_test_secret' => '', 'paystack_account_kes' => (string)$accId, 'paystack_channels' => ['card']]);
    check('db: a blank secret keeps the saved one', paystack_company_config(company_fetch($coId))['secret'] !== '');

    $id = pay_link_insert(['purpose' => 'other', 'description' => 'Kite lesson', 'amount' => 8000.0, 'currency' => 'KES',
        'customer_name' => 'J. Muller', 'hours' => 48], $coId, 'test', null);
    $l = pay_link_fetch($id);
    check('db: link made — waiting, token + reference', $l['status'] === 'waiting' && preg_match('/^[a-f0-9]{32}$/', $l['token']) && str_starts_with($l['reference'], 'TSP-'));
    check('db: found by its token', (int)pay_link_by_token((string)$l['token'])['id'] === $id);
    check('db: a malformed token finds nothing', pay_link_by_token('../../x') === false);

    // The guest opens the page and pays; Paystack is asked.
    $GLOBALS['ps_stub'] = ['/transaction/initialize' => [200, ['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/xyz']], '']];
    check('db: Pay opens a Paystack checkout', pay_link_start($l) === 'https://checkout.paystack.com/xyz');
    $init = end($GLOBALS['ps_calls'])[2];
    check('db: checkout asks for the exact amount in subunits, our reference, the company channels', $init['amount'] === 800000 && $init['currency'] === 'KES' && $init['reference'] === $l['reference'] && $init['channels'] === ['card']);
    $calls = count($GLOBALS['ps_calls']);
    pay_link_start(pay_link_fetch($id));
    check('db: a second Pay reuses the checkout (no new Paystack call)', count($GLOBALS['ps_calls']) === $calls);

    $GLOBALS['ps_stub'] = ['/transaction/verify/' => [200, ['status' => true, 'data' => ['status' => 'success', 'amount' => 80000, 'currency' => 'KES', 'channel' => 'card', 'id' => 7]], '']];
    $l = pay_link_check($id, true);
    check('db: a wrong amount at Paystack is NOT paid, and is noted', $l['status'] === 'waiting' && str_contains((string)$l['note'], 'does not match'));
    $GLOBALS['ps_stub'] = ['/transaction/verify/' => [200, ['status' => true, 'data' => ['status' => 'success', 'amount' => 800000, 'currency' => 'KES', 'channel' => 'mobile_money', 'id' => 8, 'paid_at' => '2026-10-17T09:00:00Z']], '']];
    $l = pay_link_check($id, true);
    check('db: the right payment marks it paid with the channel', $l['status'] === 'paid' && $l['paid_channel'] === 'mobile_money' && (int)$l['paystack_txn_id'] === 8);
    check('db: invoicing not live = recorded on the link only, and said so', $l['payment_id'] === null && str_contains((string)$l['note'], 'link only'));
    check('db: marking paid twice records once', pay_link_mark_paid($id, ['channel' => 'card']) === false);

    // Cancel only while waiting.
    $id2 = pay_link_insert(['purpose' => 'other', 'description' => 'X', 'amount' => 10.0, 'currency' => 'KES', 'hours' => 1], $coId, 'test', null);
    pay_link_cancel($id2, null);
    check('db: a waiting link can be cancelled', pay_link_fetch($id2)['status'] === 'cancelled');
    $refused = false; try { pay_link_cancel($id, null); } catch (PayLinkRefusal $e) { $refused = true; }
    check('db: a paid link cannot be cancelled', $refused);
    $refused = false; try { pay_link_start(pay_link_fetch($id2)); } catch (PayLinkRefusal $e) { $refused = true; }
    check('db: a cancelled link cannot be paid', $refused);

    $list = pay_link_list(['status' => 'paid'], [$coId]);
    check('db: the list filters by status and company', count($list) === 1 && (int)$list[0]['id'] === $id);
    check('db: a scope with no companies lists nothing', pay_link_list([], []) === []);

    // Invoicing live: a paid link becomes a Paystack receipt in the company's Paystack account.
    require_once __DIR__ . '/../includes/acct.php';
    if (acct_supported()) {
        db_query("UPDATE companies SET accounting_starts_on = CURRENT_DATE - 1, kra_pin = 'P051234999X' WHERE id = :c", [':c' => $coId]);
        $id3 = pay_link_insert(['purpose' => 'activity', 'description' => 'Massage', 'amount' => 9500.0, 'currency' => 'KES', 'customer_name' => 'Walk In', 'hours' => 1], $coId, 'test', null);
        pay_link_mark_paid($id3, ['channel' => 'card', 'txn_id' => 99]);
        $l3 = pay_link_fetch($id3);
        $p3 = $l3['payment_id'] ? db_query('SELECT * FROM acct_payments WHERE id = :p', [':p' => $l3['payment_id']])->fetch() : false;
        check('db: invoicing live → receipt (paystack, KES 9,500) into the Paystack account', $p3 && $p3['method'] === 'paystack' && (float)$p3['amount'] === 9500.0
            && (int)$p3['account_id'] === $accId && $p3['reference'] === $l3['reference']);
        $refused = false;
        try { acct_record_payment(1, ['method' => 'paystack', 'amount' => '1'], null); } catch (AcctRefusal $e) { $refused = true; }
        check('db: staff can’t type a "Paystack" payment by hand', $refused);
        db_query('UPDATE companies SET accounting_starts_on = NULL WHERE id = :c', [':c' => $coId]);
    } else {
        echo "SKIP  receipt checks (accounting migrations missing)\n";
    }

    // The till: priced by the real sale path, nothing sold until paid, then the sale completes.
    require_once __DIR__ . '/../includes/pos.php';
    $owner = (int) db_query("SELECT id FROM admin_users WHERE role = 'owner' AND is_active ORDER BY id LIMIT 1")->fetchColumn();
    $outlet = (int) db_query('SELECT id FROM pos_outlets WHERE is_active ORDER BY id LIMIT 1')->fetchColumn();
    if (pos_supported() && $owner && $outlet && companies_outlets_supported()) {
        db_query("UPDATE pos_outlets SET company_id = :c, currency = 'KES' WHERE id = :o", [':c' => $coId, ':o' => $outlet]);
        db_query("INSERT INTO pos_items (outlet_id, name, kind, price) VALUES (:o, 'ZZ Paystack Massage', 'service', 2500)", [':o' => $outlet]);
        $item = (int) $pdo->lastInsertId();
        $before = (int) db_query('SELECT COUNT(*) FROM pos_sales')->fetchColumn();
        $uuid = '11111111-2222-4333-8444-' . substr(bin2hex(random_bytes(6)), 0, 12);
        $req = ['outlet_id' => $outlet, 'client_uuid' => $uuid, 'lines' => [['item_id' => $item, 'qty' => 2]], 'customer' => ['type' => 'walkin', 'name' => 'Till Guest']];
        $pl = pay_link_create_pos($req, $owner, null);
        check('db: till link priced by the sale path (2 × 2,500, plus service/VAT as the outlet sets)', $pl['purpose'] === 'pos' && (float)$pl['amount'] >= 5000.0 && $pl['currency'] === 'KES');
        check('db: nothing is sold while the link waits', (int) db_query('SELECT COUNT(*) FROM pos_sales')->fetchColumn() === $before);
        check('db: the same till key returns the same link', (int)pay_link_create_pos($req, $owner, null)['id'] === (int)$pl['id']);
        $r = pos_complete_sale($req + ['payment_method' => 'paystack'], $owner, null);
        check('db: the till can’t complete a Paystack sale itself', !$r['ok']);
        pay_link_mark_paid((int)$pl['id'], ['channel' => 'mobile_money', 'txn_id' => 5]);
        $pl = pay_link_fetch((int)$pl['id']);
        $sale = $pl['pos_sale_id'] ? pos_fetch_sale((int)$pl['pos_sale_id']) : false;
        check('db: once paid, the sale completes — Paystack, same total, the link reference', $sale && $sale['payment_method'] === 'paystack'
            && pay_link_cents($sale['total']) === pay_link_cents($pl['amount']) && $sale['payment_ref'] === $pl['reference'] && $sale['client_uuid'] === $uuid);
        pay_link_complete_pos((int)$pl['id']);
        check('db: completing twice makes one sale', (int) db_query('SELECT COUNT(*) FROM pos_sales WHERE client_uuid = :u', [':u' => $uuid])->fetchColumn() === 1);
    } else {
        echo "SKIP  till checks (POS not installed / no owner or outlet)\n";
    }
} finally {
    $pdo->rollBack();
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
