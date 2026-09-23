<?php
declare(strict_types=1);
// GoHighLevel: lead push (5a) + inbound WhatsApp webhook (5b).
// Run: php -d extension=sodium tests/ghl_logic.php
// The GHL API is STUBBED (no network): ghl_request() is defined here first, so
// includes/ghl.php's function_exists guard skips the real one. DB work runs in a
// rolled-back transaction when a database is reachable.

// Configure BEFORE db.php's parse_env() caches the environment.
$_ENV['GHL_API_KEY']        = 'test-key';
$_ENV['GHL_LOCATION_ID']    = 'loc_test';
$_ENV['GHL_PIPELINE_ID']    = 'pipe_test';
$_ENV['GHL_STAGE_ID']       = 'stage_test';
$_ENV['GHL_WEBHOOK_URL']    = 'off';            // never forward anywhere from a test
$_ENV['GHL_WEBHOOK_SECRET'] = 'shh-test-secret';

$GLOBALS['ghl_calls'] = [];
$GLOBALS['ghl_contact_mode'] = 'new';          // new | duplicate | fail
function ghl_request(string $method, string $path, array $body = []): array {
    $GLOBALS['ghl_calls'][] = [$method, $path, $body];
    if ($method === 'POST' && $path === '/contacts/') {
        return match ($GLOBALS['ghl_contact_mode']) {
            'new'       => ['ok' => true,  'status' => 201, 'data' => ['contact' => ['id' => 'c_new_1']]],
            'duplicate' => ['ok' => false, 'status' => 400, 'data' => ['meta' => ['contactId' => 'c_existing_9']]],
            default     => ['ok' => false, 'status' => 500, 'data' => []],
        };
    }
    return ['ok' => true, 'status' => 200, 'data' => []];
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ghl.php';
require_once __DIR__ . '/../includes/ghl-webhook.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}
function calls_to(string $method, string $path): array {
    return array_values(array_filter($GLOBALS['ghl_calls'], fn($c) => $c[0] === $method && $c[1] === $path));
}

// ── 5a: pure lead mapping ────────────────────────────────────────
check('split name: two parts',   ghl_split_name('Jane Otieno') === ['Jane', 'Otieno']);
check('split name: three parts', ghl_split_name('Mary Jane  Otieno') === ['Mary Jane', 'Otieno']);
check('split name: one part',    ghl_split_name('Cher') === ['Cher', '']);
check('agency tagged trade-agency', ghl_type_meta('agency')['tags'] === ['trade-agency']);
check('unknown type → website',  ghl_type_meta('weird')['source'] === 'Website');

$lead = ghl_lead_from_submission([
    'id' => 42, 'type' => 'enquiry', 'guest_name' => 'Jane Otieno', 'guest_email' => 'jane@example.com',
    'guest_phone' => '+254 700 000 001', 'message' => 'Honeymoon, need a quiet room',
    'check_in' => '2026-12-01', 'check_out' => '2026-12-05', 'guests_adults' => 2, 'guests_children' => 1,
    'room_name' => 'Ocean Suite', 'venue_name' => 'Zuri',
    'payload_json' => json_encode(['quoted_total' => 1200, 'quoted_currency' => 'USD']),
]);
check('lead: first/last name',   $lead['firstName'] === 'Jane' && $lead['lastName'] === 'Otieno');
check('lead: property = venue · room', $lead['property'] === 'Zuri · Ocean Suite');
check('lead: nights computed',   $lead['nights'] === 4);
check('lead: children carried',  (int)$lead['children'] === 1);
check('lead: quoted value → opportunity', $lead['monetaryValue'] === 1200.0);
check('lead: note names the admin id', str_contains($lead['note'], 'admin #42'));
check('lead: note has the message', str_contains($lead['note'], 'quiet room'));

$cf = array_column(ghl_custom_fields(['property' => 'Zuri', 'source' => 'Website', 'adults' => 2, 'customFields' => ['ts_ref' => 'TS-1']]), 'field_value', 'key');
check('custom field keys match GHL', ($cf['property'] ?? '') === 'Zuri' && ($cf['adults'] ?? '') === '2');
check('GHL typo key enquiry_souce kept', ($cf['enquiry_souce'] ?? '') === 'Website');
check('extra custom fields pass through', ($cf['ts_ref'] ?? '') === 'TS-1');
check('empty fields are not sent', !isset($cf['arrivaldate']));

// ── 5a: push flow (stubbed API) ──────────────────────────────────
$GLOBALS['ghl_calls'] = []; $GLOBALS['ghl_contact_mode'] = 'new';
$r = ghl_push($lead);
check('push: ok with new contact id',   $r['ok'] && $r['contactId'] === 'c_new_1');
check('push: opportunity created',      count(calls_to('POST', '/opportunities/')) === 1);
check('push: opportunity in configured pipeline', (calls_to('POST', '/opportunities/')[0][2]['pipelineId'] ?? '') === 'pipe_test');
check('push: note posted',              count(calls_to('POST', '/conversations/messages')) === 1);
check('push: no PUT for a new contact', count(calls_to('PUT', '/contacts/c_new_1')) === 0);

$GLOBALS['ghl_calls'] = []; $GLOBALS['ghl_contact_mode'] = 'duplicate';
$r = ghl_push($lead);
check('push: duplicate reuses existing id', $r['ok'] && $r['contactId'] === 'c_existing_9');
check('push: duplicate gets tags via PUT',  count(calls_to('PUT', '/contacts/c_existing_9')) === 1);

$GLOBALS['ghl_calls'] = []; $GLOBALS['ghl_contact_mode'] = 'fail';
$r = ghl_push($lead);
check('push: contact failure → ok=false, no opportunity', !$r['ok'] && calls_to('POST', '/opportunities/') === []);
check('webhook forward is off in tests', ghl_forward_webhook(['x' => 1]) === false);

// ── 5b: signature + secret auth ──────────────────────────────────
check('GHL published key parses to 32 bytes', strlen((string) ghl_ed25519_key_raw(GHL_WEBHOOK_DEFAULT_PUBLIC_KEY)) === 32);
check('hex key parses',   strlen((string) ghl_ed25519_key_raw(str_repeat('ab', 32))) === 32);
check('garbage key → null', ghl_ed25519_key_raw('not a key') === null);

if (function_exists('sodium_crypto_sign_keypair')) {
    $kp   = sodium_crypto_sign_keypair();
    $pub  = sodium_crypto_sign_publickey($kp);
    $body = '{"contact_id":"c1","message":{"body":"Hi!"}}';
    $sig  = base64_encode(sodium_crypto_sign_detached($body, sodium_crypto_sign_secretkey($kp)));
    check('valid signature verifies',       ghl_verify_signature($body, $sig, $pub));
    check('tampered body is rejected',      !ghl_verify_signature($body . ' ', $sig, $pub));
    check('wrong key is rejected',          !ghl_verify_signature($body, $sig, ghl_ed25519_key_raw(GHL_WEBHOOK_DEFAULT_PUBLIC_KEY)));
    check('SPKI PEM of our key round-trips', ghl_ed25519_key_raw("-----BEGIN PUBLIC KEY-----\n" . base64_encode("\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00" . $pub) . "\n-----END PUBLIC KEY-----") === $pub);
} else {
    echo "SKIP  ext-sodium not loaded — run with: php -d extension=sodium tests/ghl_logic.php\n";
}
check('malformed signature rejected',   !ghl_verify_signature('x', 'not-base64!!', str_repeat("\0", 32)));
check('no auth at all → refused',       !ghl_webhook_authenticate('{}', []));
check('right shared secret → accepted', ghl_webhook_authenticate('{}', ['x-webhook-secret' => 'shh-test-secret']));
check('Bearer secret → accepted',       ghl_webhook_authenticate('{}', ['authorization' => 'Bearer shh-test-secret']));
check('wrong secret → refused',         !ghl_webhook_authenticate('{}', ['x-webhook-secret' => 'nope']));
check('bogus signature, no secret → refused', !ghl_webhook_authenticate('{}', ['x-ghl-signature' => base64_encode(str_repeat('a', 64))]));

// ── 5b: payload parsing ──────────────────────────────────────────
$p = ghl_webhook_parse(['contact_id' => 'c77', 'first_name' => 'Ali', 'last_name' => 'Hassan', 'email' => 'ALI@Example.com',
                        'phone' => '+254 711 222 333', 'message' => ['body' => 'Is the villa free?', 'type' => 'TYPE_WHATSAPP', 'id' => 'm1']]);
check('parse: standard payload',  $p['contact_id'] === 'c77' && $p['name'] === 'Ali Hassan' && $p['message'] === 'Is the villa free?');
check('parse: email lower-cased', $p['email'] === 'ali@example.com');
check('parse: WhatsApp channel',  $p['channel'] === 'WhatsApp');
check('parse: message id',        $p['message_id'] === 'm1');
$p2 = ghl_webhook_parse(['contact_id' => 'c78', 'full_name' => 'Bea', 'customData' => ['message' => 'Hello from custom data', 'channel' => 'SMS']]);
check('parse: custom data fallback', $p2['message'] === 'Hello from custom data' && $p2['channel'] === 'SMS' && $p2['name'] === 'Bea');
check('phone key = last 9 digits',   ghl_phone_key('+254 (0)711-222-333') === '711222333' && ghl_phone_key('123') === '');

// ── DB round-trip (rolled back) ──────────────────────────────────
$dbOk = false;
try { db(); $dbOk = true; } catch (Throwable $e) { echo "SKIP  DB unreachable — pure checks only\n"; }
if ($dbOk) {
    db()->beginTransaction();
    try {
        // 5a: push a saved submission → contact id stored on the row.
        db_query("INSERT INTO submissions (type, guest_name, guest_email, guest_phone, message)
                  VALUES ('contact', 'ZZ Ghl Lead', 'zz-ghl@example.com', '+254 799 888 777', 'hello')");
        $sid = (int) db()->lastInsertId();
        $GLOBALS['ghl_contact_mode'] = 'new';
        $r = ghl_push_submission($sid);
        $stored = db_query("SELECT payload_json->>'ghl_contact_id' FROM submissions WHERE id = :id", [':id' => $sid])->fetchColumn();
        check('DB: push_submission ok',              $r['ok'] === true);
        check('DB: GHL contact id saved on the lead', $stored === 'c_new_1');

        // 5b: a reply from that contact threads onto the same lead.
        $h = ghl_webhook_handle(ghl_webhook_parse(['contact_id' => 'c_new_1', 'message' => ['body' => 'Yes please book it', 'id' => 'zz-msg-1']]), 'msg:zz-msg-1');
        check('DB: reply threaded onto the lead', $h['action'] === 'threaded' && $h['submission_id'] === $sid);
        if (submission_notes_supported()) {
            $note = db_query("SELECT body FROM submission_notes WHERE submission_id = :id ORDER BY id DESC LIMIT 1", [':id' => $sid])->fetchColumn();
            check('DB: note text saved', is_string($note) && str_contains($note, 'Yes please book it') && str_starts_with($note, '[WhatsApp]'));
        }
        if (submission_status_supported()) {
            $st = db_query("SELECT status FROM submissions WHERE id = :id", [':id' => $sid])->fetchColumn();
            check('DB: status nudged to follow up', $st === 'to_follow_up');
        }
        if (ghl_webhook_log_supported()) {
            $again = ghl_webhook_handle(ghl_webhook_parse(['contact_id' => 'c_new_1', 'message' => ['body' => 'Yes please book it', 'id' => 'zz-msg-1']]), 'msg:zz-msg-1');
            check('DB: GHL retry is de-duplicated', $again['action'] === 'duplicate');
        } else {
            echo "SKIP  add_ghl_webhook_log not applied — de-dupe not testable\n";
        }

        // Match by phone when GHL's contact id is new to us.
        $byPhone = ghl_webhook_handle(ghl_webhook_parse(['contact_id' => 'c_other', 'phone' => '0799888777', 'message' => ['body' => 'hi again']]), 'msg:zz-msg-2');
        check('DB: matched by phone number', $byPhone['action'] === 'threaded' && $byPhone['submission_id'] === $sid);

        // Unknown sender → new lead.
        $new = ghl_webhook_handle(ghl_webhook_parse(['contact_id' => 'zz-unknown-contact', 'full_name' => 'New Person', 'phone' => '+44 7700 900123', 'message' => ['body' => 'Do you have rooms?']]), 'msg:zz-msg-3');
        check('DB: unknown sender opens a new lead', $new['action'] === 'created' && $new['submission_id'] > 0);
        $row = db_query("SELECT type, guest_name, payload_json->>'source' AS src FROM submissions WHERE id = :id", [':id' => $new['submission_id']])->fetch();
        check('DB: new lead is a WhatsApp contact', $row['type'] === 'contact' && $row['guest_name'] === 'New Person' && $row['src'] === 'whatsapp');

        // Nothing to identify the sender → ignored, nothing written.
        $ign = ghl_webhook_handle(ghl_webhook_parse(['message' => ['body' => 'anon']]), 'msg:zz-msg-4');
        check('DB: anonymous payload ignored', $ign['action'] === 'ignored');
    } finally {
        db()->rollBack();
    }
}

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
