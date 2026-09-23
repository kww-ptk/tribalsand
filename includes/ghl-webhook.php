<?php
declare(strict_types=1);
/**
 * Inbound GoHighLevel webhooks — WhatsApp (and other channel) replies from GHL
 * land in our submissions inbox as a lead touch. Endpoint: api/ghl-webhook.php.
 *
 * Source: a GHL Workflow, built in the GHL UI —
 *   Trigger "Customer Replied" (filter: reply channel = WhatsApp)
 *   → Action "Webhook" (POST https://tribalsand.com/api/ghl-webhook.php).
 * Setup runbook: docs/ghl-whatsapp-webhook.md.
 *
 * AUTH (verify before trust, like sns_verify_signature() for inbound mail):
 *   1. GHL's Ed25519 signature — header X-GHL-Signature (base64) over the RAW
 *      body, checked against GHL's published public key (override with
 *      GHL_WEBHOOK_PUBLIC_KEY), and/or
 *   2. a shared secret the workflow sends as a custom header
 *      (X-Webhook-Secret: <GHL_WEBHOOK_SECRET>), for workflow actions that
 *      don't sign. Only honoured when GHL_WEBHOOK_SECRET is set (non-empty).
 * Anything else is refused — the endpoint is public, and an unauthenticated
 * post must never be able to plant a "guest reply" in a lead's thread.
 *
 * WHAT IT DOES: matches the sender to an existing lead (the GHL contact id we
 * stored at push time → email → phone) and threads the text as a `guest_reply`
 * note, flags it unread and nudges the status — the same path an emailed guest
 * reply takes (api/inbound-mail.php). No match → a new `contact` submission so
 * the conversation is still visible. It deliberately does NOT rebuild GHL's
 * Conversations UI; staff answer WhatsApp in GHL.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/submission-notes.php';
require_once __DIR__ . '/submission-status.php';

/** GHL's published Ed25519 public key for X-GHL-Signature (Webhook Integration Guide). */
const GHL_WEBHOOK_DEFAULT_PUBLIC_KEY = "-----BEGIN PUBLIC KEY-----\nMCowBQYDK2VwAyEAi2HR1srL4o18O8BRa7gVJY7G7bupbN3H9AwJrHCDiOg=\n-----END PUBLIC KEY-----";

const GHL_WEBHOOK_MAX_BYTES = 262144;   // 256 KB — a text reply is tiny

/**
 * Raw 32-byte Ed25519 public key from PEM (SPKI), base64 (raw 32 bytes or the
 * SPKI DER) or 64-char hex. PURE + testable. null when unusable.
 */
function ghl_ed25519_key_raw(string $key): ?string {
    $key = trim($key);
    if ($key === '') return null;
    if (preg_match('/^[0-9a-fA-F]{64}$/', $key)) return hex2bin($key);
    if (str_contains($key, 'BEGIN PUBLIC KEY')) {
        $key = (string)preg_replace('/-----[A-Z ]+-----|\s+/', '', $key);
    }
    $bin = base64_decode($key, true);
    if ($bin === false) return null;
    if (strlen($bin) === 32) return $bin;
    // SPKI DER for Ed25519: 30 2a 30 05 06 03 2b 65 70 03 21 00 || 32-byte key.
    if (strlen($bin) === 44 && str_starts_with($bin, "\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00")) return substr($bin, 12);
    return null;
}

/** The public key to verify against (env override, else GHL's published key). */
function ghl_webhook_public_key(): ?string {
    $env = trim((string)(parse_env()['GHL_WEBHOOK_PUBLIC_KEY'] ?? ''));
    return ghl_ed25519_key_raw($env !== '' ? $env : GHL_WEBHOOK_DEFAULT_PUBLIC_KEY);
}

/**
 * Verify a base64 Ed25519 signature over the EXACT raw body. PURE (given the
 * key). Fails closed: no sodium, bad key or malformed signature → false.
 */
function ghl_verify_signature(string $rawBody, string $signatureB64, ?string $publicKeyRaw): bool {
    if ($publicKeyRaw === null || strlen($publicKeyRaw) !== 32) return false;
    $sig = base64_decode(trim($signatureB64), true);
    if ($sig === false || strlen($sig) !== 64) return false;
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        error_log('[ghl-webhook] ext-sodium missing — cannot verify X-GHL-Signature (failing closed)');
        return false;
    }
    try { return sodium_crypto_sign_verify_detached($sig, $rawBody, $publicKeyRaw); }
    catch (Throwable $e) { return false; }
}

/**
 * Is this request genuinely from our GHL workflow? $headers is lower-cased
 * name => value. Signature first; the shared secret only when configured.
 */
function ghl_webhook_authenticate(string $rawBody, array $headers): bool {
    $sig = (string)($headers['x-ghl-signature'] ?? '');
    if ($sig !== '' && ghl_verify_signature($rawBody, $sig, ghl_webhook_public_key())) return true;

    $secret = (string)(parse_env()['GHL_WEBHOOK_SECRET'] ?? '');
    if ($secret === '') return false;   // hash_equals('', '') is true — never compare an empty secret
    $given = (string)($headers['x-webhook-secret'] ?? '');
    if ($given === '' && preg_match('/^Bearer\s+(.+)$/i', (string)($headers['authorization'] ?? ''), $m)) $given = trim($m[1]);
    return $given !== '' && hash_equals($secret, $given);
}

/** Friendly channel label from whatever GHL sent. PURE. Defaults to WhatsApp (the workflow filters on it). */
function ghl_channel_label($raw): string {
    $r = strtolower(trim((string)$raw));
    return match (true) {
        $r === '' || is_numeric($r)                          => 'WhatsApp',
        str_contains($r, 'whatsapp')                         => 'WhatsApp',
        str_contains($r, 'sms')                              => 'SMS',
        str_contains($r, 'email')                            => 'Email',
        str_contains($r, 'instagram') || $r === 'ig'         => 'Instagram',
        str_contains($r, 'facebook') || str_contains($r, 'fb') || str_contains($r, 'messenger') => 'Facebook',
        str_contains($r, 'live_chat') || str_contains($r, 'chat') => 'Website chat',
        default                                              => ucfirst($r),
    };
}

/**
 * Normalise a GHL workflow webhook body. PURE + testable. Accepts the standard
 * workflow payload (contact_id, full_name/first_name/last_name, email, phone,
 * message{body,type,id}) plus Custom Data keys (customData.message / channel /
 * message_id) and a few flat fallbacks, since each workflow is hand-built.
 */
function ghl_webhook_parse(array $p): array {
    $cd  = is_array($p['customData'] ?? null) ? $p['customData'] : (is_array($p['custom_data'] ?? null) ? $p['custom_data'] : []);
    $msg = $p['message'] ?? null;

    $body = '';
    if (is_array($msg))       $body = (string)($msg['body'] ?? ($msg['text'] ?? ''));
    elseif (is_string($msg))  $body = $msg;
    if ($body === '')         $body = (string)($cd['message'] ?? ($cd['body'] ?? ($p['body'] ?? ($p['text'] ?? ''))));

    $name = trim((string)($p['full_name'] ?? ($p['contact_name'] ?? '')));
    if ($name === '') $name = trim(((string)($p['first_name'] ?? '')) . ' ' . ((string)($p['last_name'] ?? '')));
    if ($name === '' && isset($p['contact']) && is_array($p['contact'])) $name = trim((string)($p['contact']['name'] ?? ''));

    $contactId = (string)($p['contact_id'] ?? ($p['contactId'] ?? (is_array($p['contact'] ?? null) ? ($p['contact']['id'] ?? '') : '')));
    $channelRaw = $cd['channel'] ?? (is_array($msg) ? ($msg['type'] ?? ($msg['messageType'] ?? '')) : ($p['channel'] ?? ''));
    $messageId  = (string)($cd['message_id'] ?? (is_array($msg) ? ($msg['id'] ?? '') : '') ?: ($p['messageId'] ?? ($p['message_id'] ?? '')));

    return [
        'contact_id' => trim($contactId),
        'name'       => mb_substr($name, 0, 200),
        'email'      => strtolower(trim((string)($p['email'] ?? ''))),
        'phone'      => trim((string)($p['phone'] ?? '')),
        'message'    => trim(mb_substr($body, 0, 5000)),
        'channel'    => ghl_channel_label($channelRaw),
        'message_id' => trim((string)$messageId),
    ];
}

/** Last 9 digits of a phone number (Kenyan mobiles: 7xx xxx xxx), '' if too short. PURE. */
function ghl_phone_key(string $phone): string {
    $d = (string)preg_replace('/\D+/', '', $phone);
    return strlen($d) >= 9 ? substr($d, -9) : '';
}

/**
 * The lead this reply belongs to: the most recent submission carrying the GHL
 * contact id we stored at push time, else the same email, else the same phone.
 */
function ghl_webhook_find_submission(array $parsed): ?array {
    $tries = [];
    if ($parsed['contact_id'] !== '') $tries[] = ["payload_json->>'ghl_contact_id' = :v", $parsed['contact_id']];
    if (filter_var($parsed['email'], FILTER_VALIDATE_EMAIL)) $tries[] = ['lower(guest_email) = :v', $parsed['email']];
    if (($pk = ghl_phone_key($parsed['phone'])) !== '') $tries[] = ["right(regexp_replace(COALESCE(guest_phone,''), '\\D', '', 'g'), 9) = :v", $pk];
    foreach ($tries as [$cond, $val]) {
        $row = db_query("SELECT * FROM submissions WHERE {$cond} ORDER BY created_at DESC, id DESC LIMIT 1", [':v' => $val])->fetch();
        if ($row) return $row;
    }
    return null;
}

/** True once add_ghl_webhook_log.sql is applied (memoised). */
function ghl_webhook_log_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.ghl_webhook_log')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/** Already processed this delivery? Always false pre-migration (safe: it just can't de-dupe). */
function ghl_webhook_seen(string $key): bool {
    if ($key === '' || !ghl_webhook_log_supported()) return false;
    try { return (bool) db_query('SELECT 1 FROM ghl_webhook_log WHERE dedupe_key = :k', [':k' => $key])->fetchColumn(); }
    catch (Throwable $e) { return false; }
}

/** Record a processed delivery. Best-effort. */
function ghl_webhook_record(string $key, array $parsed, ?int $submissionId, string $action): void {
    if ($key === '' || !ghl_webhook_log_supported()) return;
    try {
        db_query(
            "INSERT INTO ghl_webhook_log (dedupe_key, contact_id, channel, submission_id, action)
             VALUES (:k, :c, :ch, :s, :a) ON CONFLICT (dedupe_key) DO NOTHING",
            [':k' => mb_substr($key, 0, 300), ':c' => $parsed['contact_id'] ?: null, ':ch' => $parsed['channel'],
             ':s' => $submissionId ?: null, ':a' => $action]
        );
    } catch (Throwable $e) { error_log('[ghl-webhook] log insert failed: ' . $e->getMessage()); }
}

/**
 * Thread (or create) the lead touch. Returns ['action' => threaded|created|duplicate|ignored, 'submission_id' => ?int].
 * $dedupeKey: the GHL message id, else a hash of the raw body.
 */
function ghl_webhook_handle(array $parsed, string $dedupeKey): array {
    if (ghl_webhook_seen($dedupeKey)) return ['action' => 'duplicate', 'submission_id' => null];
    if ($parsed['contact_id'] === '' && $parsed['email'] === '' && ghl_phone_key($parsed['phone']) === '') {
        ghl_webhook_record($dedupeKey, $parsed, null, 'ignored');
        return ['action' => 'ignored', 'submission_id' => null];
    }

    $ch   = $parsed['channel'];
    $text = $parsed['message'] !== ''
        ? $parsed['message']
        : "(The guest sent a {$ch} message with no text — a photo, voice note or file. Open the conversation in GHL to see it.)";

    $sub = ghl_webhook_find_submission($parsed);
    if ($sub) {
        $sid    = (int)$sub['id'];
        $author = trim(($parsed['name'] !== '' ? $parsed['name'] : (string)($sub['guest_name'] ?? 'Guest')) . " ({$ch})");
        $noteId = add_submission_note($sid, null, "[{$ch}] " . $text, 'guest_reply', $author);
        if ($noteId) {
            submission_mark_guest_reply($sid);
            if (submission_status_supported()) {
                try {
                    db_query(
                        "UPDATE submissions SET status = 'to_follow_up'
                         WHERE id = :id AND status NOT IN ('booked','not_interested','dates_unavailable')",
                        [':id' => $sid]
                    );
                } catch (Throwable $e) { error_log('[ghl-webhook] status nudge failed: ' . $e->getMessage()); }
            }
            // Remember the GHL contact on the lead so the next reply matches directly.
            if ($parsed['contact_id'] !== '' && empty(json_decode((string)($sub['payload_json'] ?? '{}'), true)['ghl_contact_id'])) {
                try {
                    db_query(
                        "UPDATE submissions SET payload_json = COALESCE(payload_json,'{}'::jsonb) || jsonb_build_object('ghl_contact_id', CAST(:c AS text)) WHERE id = :id",
                        [':c' => $parsed['contact_id'], ':id' => $sid]
                    );
                } catch (Throwable $e) {}
            }
        }
        ghl_webhook_record($dedupeKey, $parsed, $sid, 'threaded');
        return ['action' => 'threaded', 'submission_id' => $sid];
    }

    // Nobody we know — open a new lead so the conversation is still visible.
    db_query(
        "INSERT INTO submissions (type, guest_name, guest_email, guest_phone, message, payload_json)
         VALUES ('contact', :n, :e, :p, :m, :pl)",
        [
            ':n'  => $parsed['name'] !== '' ? $parsed['name'] : ($parsed['phone'] !== '' ? $parsed['phone'] : "{$ch} contact"),
            ':e'  => $parsed['email'],
            ':p'  => $parsed['phone'],
            ':m'  => $text,
            ':pl' => json_encode(array_filter([
                'subject'        => "{$ch} message",
                'source'         => 'whatsapp',
                'channel'        => $ch,
                'ghl_contact_id' => $parsed['contact_id'],
            ], fn($v) => $v !== ''), JSON_UNESCAPED_UNICODE),
        ]
    );
    $sid = (int) db()->lastInsertId();
    submission_mark_guest_reply($sid);
    ghl_webhook_record($dedupeKey, $parsed, $sid, 'created');
    return ['action' => 'created', 'submission_id' => $sid];
}
