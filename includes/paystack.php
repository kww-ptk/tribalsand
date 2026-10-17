<?php
declare(strict_types=1);
/**
 * Paystack — the ONE file that talks to Paystack, plus each company's Paystack
 * settings (Company → Paystack tab). Payment links live in includes/pay-links.php.
 * Migration: db/migrations/add_paystack.sql. Test: php tests/paystack_logic.php.
 *
 * Rules that are load-bearing:
 *  - Each company has its own Paystack account: a link is always paid to the
 *    company that is owed the money, with that company's keys.
 *  - No webhook. Paystack allows one webhook address per account and the company's
 *    other system already uses it, so we ASK Paystack (transaction/verify) when the
 *    guest comes back, when the till / admin looks, and every few minutes from the
 *    scheduler. A payment is trusted only when verify says success for the exact
 *    amount and currency.
 *  - Secret keys are stored encrypted (AES-256-GCM, key from PAYMENT_KEYS_SECRET,
 *    else BOOKING_TOKEN_SECRET) and are never printed back into a page.
 *  - "Practice" mode makes pretend payments and is refused on the live site
 *    (paystack_practice_allowed()).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/companies.php';

const PAYSTACK_API        = 'https://api.paystack.co';
const PAYSTACK_MODES      = ['off' => 'Off', 'practice' => 'Practice', 'test' => 'Test', 'live' => 'Live'];
const PAYSTACK_CHANNELS   = ['card' => 'Card', 'mobile_money' => 'M-Pesa', 'bank_transfer' => 'Bank transfer'];
const PAYSTACK_CURRENCIES = ['KES', 'USD'];
const PAYSTACK_CHECK_GAP  = 5;   // seconds between two verify calls for one link

/** add_paystack.sql has run. Catalog lookups — safe inside a transaction. */
function paystack_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    return $ok = companies_supported() && to_regclass_exists('pay_links') && company_column_exists('companies', 'paystack_mode');
}

/**
 * Pretend payments are for test servers only. PAYSTACK_PRACTICE=on|off decides when
 * set; otherwise practice is allowed everywhere except the live site's own host.
 */
function paystack_practice_allowed(?array $env = null): bool {
    $env ??= parse_env();
    $flag = strtolower(trim((string)($env['PAYSTACK_PRACTICE'] ?? '')));
    if (in_array($flag, ['on', '1', 'yes', 'true'], true)) return true;
    if (in_array($flag, ['off', '0', 'no', 'false'], true)) return false;
    $host = strtolower((string)(parse_url((string)($env['APP_URL'] ?? 'https://tribalsand.com'), PHP_URL_HOST) ?: ''));
    return !in_array($host, ['tribalsand.com', 'www.tribalsand.com'], true);
}

// ── Keys ────────────────────────────────────────────────────────────────────

/** Is a key well formed for that mode? Pure. Returns a message or null. */
function paystack_key_problem(string $kind, string $mode, string $key): ?string {
    $prefix = ($kind === 'secret' ? 'sk_' : 'pk_') . $mode . '_';
    $label  = ($mode === 'live' ? 'Live' : 'Test') . ' ' . ($kind === 'secret' ? 'secret' : 'public') . ' key';
    if ($key === '') return null;
    if (!str_starts_with($key, $prefix)) return "The {$label} starts with {$prefix}";
    if (!preg_match('/^[a-z]{2}_(test|live)_[A-Za-z0-9]{16,100}$/', $key)) return "The {$label} doesn't look right — copy it again from Paystack.";
    return null;
}

function paystack_crypto_key(): ?string {
    $env = parse_env();
    $s = (string)($env['PAYMENT_KEYS_SECRET'] ?? '');
    if ($s === '') $s = (string)($env['BOOKING_TOKEN_SECRET'] ?? '');
    return $s === '' ? null : hash('sha256', 'paystack-keys|' . $s, true);
}

/** Encrypt a secret key for storage ('' stays ''). Throws when no app secret is set. */
function paystack_encrypt(string $plain): string {
    if ($plain === '') return '';
    $k = paystack_crypto_key();
    if ($k === null) throw new CompanyRefusal('The server has no PAYMENT_KEYS_SECRET (or BOOKING_TOKEN_SECRET) set, so keys can’t be stored safely.');
    $iv = random_bytes(12); $tag = '';
    $c = openssl_encrypt($plain, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag);
    if ($c === false) throw new CompanyRefusal('The key could not be encrypted.');
    return 'v1:' . base64_encode($iv . $tag . $c);
}

/** The stored secret, or '' when none / unreadable (e.g. the app secret changed). */
function paystack_decrypt(string $enc): string {
    if (!str_starts_with($enc, 'v1:')) return '';
    $k = paystack_crypto_key(); if ($k === null) return '';
    $raw = base64_decode(substr($enc, 3), true);
    if ($raw === false || strlen($raw) < 29) return '';
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? '' : $p;
}

/** "sk_live_…a1b2" — enough to recognise a key, never the key. Pure. */
function paystack_mask(string $key): string {
    return $key === '' ? '' : substr($key, 0, 8) . '…' . substr($key, -4);
}

// ── A company's settings ────────────────────────────────────────────────────

/**
 * Everything a link needs from a company row: mode, the keys of that mode,
 * accounts per currency, channels, and whether it can take payments now.
 */
function paystack_company_config(array|false $co): array {
    $cfg = ['mode' => 'off', 'public' => '', 'secret' => '', 'accounts' => [], 'channels' => [], 'ready' => false, 'problem' => 'Paystack is not set up for this company.'];
    if (!$co || !paystack_supported()) return $cfg;
    $mode = (string)($co['paystack_mode'] ?? 'off');
    $cfg['mode'] = isset(PAYSTACK_MODES[$mode]) ? $mode : 'off';
    if (in_array($cfg['mode'], ['test', 'live'], true)) {
        $cfg['public'] = (string)$co['paystack_' . $cfg['mode'] . '_public'];
        $cfg['secret'] = paystack_decrypt((string)$co['paystack_' . $cfg['mode'] . '_secret_enc']);
    }
    foreach (PAYSTACK_CURRENCIES as $c) {
        $a = (int)($co['paystack_account_' . strtolower($c)] ?? 0);
        if ($a > 0) $cfg['accounts'][$c] = $a;
    }
    $cfg['channels'] = array_values(array_intersect(array_keys(PAYSTACK_CHANNELS), explode(',', (string)($co['paystack_channels'] ?? ''))));
    $cfg['problem'] = match (true) {
        !in_array($co['is_active'], [true, 't', 1, '1'], true)               => "{$co['name']} is switched off.",
        $cfg['mode'] === 'off'                                              => "Paystack is off for {$co['name']} — the owner sets it in Accounting → Companies → {$co['name']} → Paystack.",
        $cfg['mode'] === 'practice' && !paystack_practice_allowed()         => "{$co['name']} is in Practice mode, which doesn't work on the live site.",
        $cfg['mode'] !== 'practice' && $cfg['secret'] === ''                => "{$co['name']} has no Paystack " . $cfg['mode'] . ' secret key yet.',
        !$cfg['accounts']                                                   => "Pick which account Paystack payments go into for {$co['name']}.",
        !$cfg['channels']                                                   => "Pick at least one way to pay for {$co['name']}.",
        default                                                             => '',
    };
    $cfg['ready'] = $cfg['problem'] === '';
    return $cfg;
}

/** Save the Paystack tab. A blank secret keeps the stored one; 'forget_<mode>' clears it. */
function paystack_save_settings(int $companyId, array $in): void {
    $co = company_fetch($companyId);
    if (!$co) throw new CompanyRefusal('That company no longer exists.');
    $mode = (string)($in['paystack_mode'] ?? 'off');
    if (!isset(PAYSTACK_MODES[$mode])) throw new CompanyRefusal('Pick a mode.');
    if ($mode === 'practice' && !paystack_practice_allowed()) throw new CompanyRefusal('Practice mode is for test servers — use Test or Live here.');
    $set = ['paystack_mode' => $mode];
    foreach (['test', 'live'] as $m) {
        $pub = trim((string)($in['paystack_' . $m . '_public'] ?? ''));
        $sec = trim((string)($in['paystack_' . $m . '_secret'] ?? ''));
        if ($p = paystack_key_problem('public', $m, $pub)) throw new CompanyRefusal($p);
        if ($p = paystack_key_problem('secret', $m, $sec)) throw new CompanyRefusal($p);
        $set['paystack_' . $m . '_public'] = $pub;
        if ($sec !== '') $set['paystack_' . $m . '_secret_enc'] = paystack_encrypt($sec);
        elseif (!empty($in['forget_' . $m])) $set['paystack_' . $m . '_secret_enc'] = '';
    }
    $accounts = [];
    foreach (company_accounts($companyId, true) as $a) $accounts[(int)$a['id']] = $a;
    foreach (PAYSTACK_CURRENCIES as $c) {
        $aid = (int)($in['paystack_account_' . strtolower($c)] ?? 0);
        if ($aid && (!isset($accounts[$aid]) || strtoupper((string)$accounts[$aid]['currency']) !== $c)) {
            throw new CompanyRefusal("Pick one of {$co['name']}'s {$c} accounts for {$c} payments.");
        }
        $set['paystack_account_' . strtolower($c)] = $aid ?: null;
    }
    $ch = array_values(array_intersect(array_keys(PAYSTACK_CHANNELS), (array)($in['paystack_channels'] ?? [])));
    $set['paystack_channels'] = implode(',', $ch);
    $after = array_merge($co, $set);
    if (in_array($mode, ['test', 'live'], true) && ($after['paystack_' . $mode . '_secret_enc'] ?? '') === '') {
        throw new CompanyRefusal('Paste the ' . $mode . ' secret key to switch to ' . PAYSTACK_MODES[$mode] . '.');
    }
    $cols = []; $p = [':id' => $companyId];
    foreach ($set as $k => $v) { $cols[] = "{$k} = :{$k}"; $p[":{$k}"] = $v; }
    db_query('UPDATE companies SET ' . implode(', ', $cols) . ', updated_at = now() WHERE id = :id', $p);
}

// ── Talking to Paystack ─────────────────────────────────────────────────────

if (!function_exists('paystack_http')) {
    /** One HTTPS call. Returns [status, decoded body|null, error]. Stubbed in tests. */
    function paystack_http(string $secret, string $method, string $path, ?array $body = null): array {
        $ch = curl_init(PAYSTACK_API . $path);
        $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $secret, 'Content-Type: application/json', 'Accept: application/json']];
        if ($method === 'POST') { $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = json_encode($body ?? []); }
        curl_setopt_array($ch, $opt);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = $raw === false ? curl_error($ch) : '';
        curl_close($ch);
        return [$status, is_string($raw) ? json_decode($raw, true) : null, $err];
    }
}

/** ['ok', 'data', 'error'] — 'error' is safe to show staff. */
function paystack_call(string $secret, string $method, string $path, ?array $body = null): array {
    if ($secret === '') return ['ok' => false, 'data' => null, 'error' => 'No Paystack key.'];
    [$status, $json, $err] = paystack_http($secret, $method, $path, $body);
    if ($err !== '' || $status === 0) return ['ok' => false, 'data' => null, 'error' => 'Paystack could not be reached — try again.'];
    if ($status === 401) return ['ok' => false, 'data' => null, 'error' => 'Paystack refused the key — check it on the company’s Paystack tab.'];
    if (!is_array($json) || empty($json['status'])) {
        return ['ok' => false, 'data' => $json['data'] ?? null, 'error' => 'Paystack: ' . (is_array($json) && !empty($json['message']) ? (string)$json['message'] : 'unexpected reply (' . $status . ').')];
    }
    return ['ok' => true, 'data' => $json['data'] ?? null, 'error' => ''];
}

/** Does the key work? */
function paystack_test_connection(string $secret): array {
    return paystack_call($secret, 'GET', '/transaction?perPage=1');
}

/** Start a checkout → ['ok', 'url', 'error']. */
function paystack_initialize(string $secret, array $p): array {
    $r = paystack_call($secret, 'POST', '/transaction/initialize', $p);
    if (!$r['ok']) return ['ok' => false, 'url' => '', 'error' => $r['error']];
    $url = (string)($r['data']['authorization_url'] ?? '');
    return $url === '' ? ['ok' => false, 'url' => '', 'error' => 'Paystack did not return a checkout page.'] : ['ok' => true, 'url' => $url, 'error' => ''];
}

/**
 * Ask Paystack what happened to a reference → ['ok', 'status', 'amount' (cents),
 * 'currency', 'channel', 'id', 'paid_at', 'error']. 'status' is Paystack's own
 * (success, failed, abandoned, ongoing, pending…); a reference the guest never
 * opened is 'not_found'.
 */
function paystack_verify(string $secret, string $reference): array {
    $r = paystack_call($secret, 'GET', '/transaction/verify/' . rawurlencode($reference));
    $out = ['ok' => $r['ok'], 'status' => '', 'amount' => 0, 'currency' => '', 'channel' => '', 'id' => null, 'paid_at' => null, 'error' => $r['error']];
    if (!$r['ok']) {
        if (str_contains(strtolower($r['error']), 'not found')) { $out['ok'] = true; $out['status'] = 'not_found'; $out['error'] = ''; }
        return $out;
    }
    $d = (array)$r['data'];
    $out['status']   = strtolower((string)($d['status'] ?? ''));
    $out['amount']   = (int)($d['amount'] ?? 0);
    $out['currency'] = strtoupper((string)($d['currency'] ?? ''));
    $out['channel']  = (string)($d['channel'] ?? '');
    $out['id']       = isset($d['id']) ? (int)$d['id'] : null;
    $out['paid_at']  = !empty($d['paid_at']) ? (string)$d['paid_at'] : null;
    return $out;
}
