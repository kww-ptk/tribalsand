<?php
declare(strict_types=1);
/**
 * Inbound guest-reply intake helpers (AWS SES → SNS → api/inbound-mail.php).
 *
 * When a guest replies to one of our outgoing enquiry replies, SES receives it on
 * reply@mail.tribalsand.com, an SES receipt rule publishes it to an SNS topic, and
 * SNS HTTP-POSTs the notification to api/inbound-mail.php. This file holds the
 * plumbing that endpoint needs:
 *   - SNS message-signature verification (so only genuine AWS deliveries are honoured)
 *   - a minimal MIME extractor (raw email → plain-text reply, quoted history stripped)
 *   - a de-dup / observability log (inbound_mail_log)
 *
 * The submission match itself (subject [TSR-<id>] tag → submission id) uses
 * verify_submission_ref() in includes/booking.php, which re-checks the HMAC so a
 * forged tag can't inject a reply into an arbitrary thread.
 *
 * Depends on includes/db.php (db_query, e()). Every DB read is pre-migration-safe.
 */

require_once __DIR__ . '/db.php';

/* ------------------------------------------------------------------ *
 *  De-dup / observability log (add_inbound_mail_log.sql)
 * ------------------------------------------------------------------ */

/** True once the inbound_mail_log table exists (memoised). False pre-migration. */
function inbound_mail_log_supported(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $r = db_query(
            "SELECT 1 FROM information_schema.tables
             WHERE table_name = 'inbound_mail_log' LIMIT 1"
        )->fetch();
        return $cached = (bool) $r;
    } catch (Throwable $e) { return $cached = false; }
}

/**
 * True if we've already accepted this SES messageId. Pre-migration this always
 * returns false (no de-dup possible), which is safe — replies still thread, a
 * duplicate SNS delivery would just post twice.
 */
function inbound_mail_already_seen(string $messageId): bool {
    $messageId = trim($messageId);
    if ($messageId === '' || !inbound_mail_log_supported()) return false;
    try {
        return (bool) db_query(
            "SELECT 1 FROM inbound_mail_log WHERE message_id = :m LIMIT 1",
            [':m' => $messageId]
        )->fetchColumn();
    } catch (Throwable $e) {
        error_log('[inbound-mail] seen check failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Record an accepted inbound email. Best-effort: a logging failure never breaks
 * the reply that was already threaded. No-op pre-migration.
 */
function inbound_mail_record(string $messageId, ?int $submissionId, string $from, string $subject): void {
    if (trim($messageId) === '' || !inbound_mail_log_supported()) return;
    try {
        db_query(
            "INSERT INTO inbound_mail_log (message_id, submission_id, from_addr, subject, matched)
             VALUES (:m, :sid, :from, :subj, :matched)
             ON CONFLICT (message_id) DO NOTHING",
            [
                ':m'       => $messageId,
                ':sid'     => $submissionId ?: null,
                ':from'    => mb_substr($from, 0, 320),
                ':subj'    => mb_substr($subject, 0, 998),
                ':matched' => $submissionId ? 't' : 'f',
            ]
        );
    } catch (Throwable $e) {
        error_log('[inbound-mail] log insert failed: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------ *
 *  SNS signature verification
 * ------------------------------------------------------------------ */

/**
 * Verify an Amazon SNS message signature. Returns true only for a genuine,
 * untampered SNS delivery. This is the webhook's authentication: without it,
 * anyone who found the endpoint URL could POST a fake "guest reply".
 *
 * Follows the documented canonical-string rules: the signed fields and their
 * order depend on the message Type, each emitted as "key\nvalue\n". Signature
 * version 1 = SHA1, version 2 = SHA256. The signing certificate is fetched from
 * SigningCertURL, whose host is validated to be an AWS SNS endpoint first.
 */
function sns_verify_signature(array $msg): bool {
    $type = (string)($msg['Type'] ?? '');
    $sig  = (string)($msg['Signature'] ?? '');
    $certUrl = (string)($msg['SigningCertURL'] ?? $msg['SigningCertUrl'] ?? '');
    if ($type === '' || $sig === '' || $certUrl === '') return false;

    // Fields to sign, in the SNS-mandated order, per message type.
    if ($type === 'Notification') {
        $fields = ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'];
    } elseif ($type === 'SubscriptionConfirmation' || $type === 'UnsubscribeConfirmation') {
        $fields = ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
    } else {
        return false;
    }

    $canonical = '';
    foreach ($fields as $f) {
        // Subject is optional; skip when absent (matching SNS's own behaviour).
        if (!array_key_exists($f, $msg)) continue;
        $canonical .= $f . "\n" . (string)$msg[$f] . "\n";
    }

    $pem = sns_fetch_certificate($certUrl);
    if ($pem === null) return false;

    $pubkey = openssl_pkey_get_public($pem);
    if ($pubkey === false) return false;

    $algo = ((string)($msg['SignatureVersion'] ?? '1') === '2')
        ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;

    $ok = openssl_verify($canonical, base64_decode($sig), $pubkey, $algo);
    return $ok === 1;
}

/**
 * Fetch (and briefly cache) the SNS signing certificate. Rejects any URL whose
 * host is not an amazonaws.com SNS endpoint served over https — the critical
 * guard, since an attacker who could point us at their own cert could forge
 * signatures. Returns the PEM string, or null on any failure.
 */
function sns_fetch_certificate(string $url): ?string {
    $parts = parse_url($url);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https') return null;
    $host = strtolower($parts['host'] ?? '');
    // e.g. sns.eu-west-1.amazonaws.com
    if (!preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com$/', $host)) return null;
    if (!preg_match('/\.pem$/', (string)($parts['path'] ?? ''))) return null;

    // Cheap cross-request cache: certs rarely rotate, and each SNS delivery is a
    // fresh PHP process, so re-fetching every time would add a round-trip.
    $cacheFile = sys_get_temp_dir() . '/sns-cert-' . sha1($url) . '.pem';
    if (is_readable($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
        $cached = file_get_contents($cacheFile);
        if ($cached !== false && $cached !== '') return $cached;
    }

    $pem = inbound_http_get($url);
    if ($pem === null || stripos($pem, 'BEGIN CERTIFICATE') === false) return null;

    @file_put_contents($cacheFile, $pem, LOCK_EX);
    return $pem;
}

/**
 * Confirm an SNS subscription by GETting its SubscribeURL. SNS sends a
 * SubscriptionConfirmation once, when the HTTPS subscription is first created;
 * fetching the URL activates it. Returns true on a 2xx. The URL's host is
 * validated the same way as the cert URL.
 */
function sns_confirm_subscription(string $subscribeUrl): bool {
    $parts = parse_url($subscribeUrl);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https') return false;
    $host = strtolower($parts['host'] ?? '');
    if (!preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com$/', $host)) return false;
    return inbound_http_get($subscribeUrl) !== null;
}

/** GET a URL over https with TLS verification. Returns the body or null. */
function inbound_http_get(string $url): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'TribalSand/1.0 InboundMail',
        ]);
        // Local Windows dev has no system CA store (see CLAUDE.md); honour an
        // explicit bundle when provided so the cert/confirm fetch works there too.
        $ca = ini_get('curl.cainfo');
        if ($ca && is_readable($ca)) curl_setopt($ch, CURLOPT_CAINFO, $ca);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) return null;
        return (string) $body;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? null : $body;
}

/* ------------------------------------------------------------------ *
 *  MIME → plain-text reply
 * ------------------------------------------------------------------ */

/**
 * Extract the guest's actual reply text from a raw RFC-822 message. Prefers the
 * text/plain part, falls back to text/html (tags stripped), decodes the
 * transfer-encoding and charset, then strips the quoted history of our original
 * mail. Best-effort: returns '' if nothing usable is found.
 */
function inbound_extract_text(string $raw): string {
    $raw  = str_replace("\r\n", "\n", $raw);
    $text = inbound_mime_first_text($raw);
    if ($text === '') return '';
    return inbound_strip_quoted($text);
}

/**
 * Walk a MIME message/part and return the first text/plain body (decoded), or a
 * tag-stripped text/html body if that's all there is. Recurses into multipart.
 */
function inbound_mime_first_text(string $part): string {
    $split = preg_split("/\n\n/", $part, 2);
    $head  = $split[0] ?? '';
    $body  = $split[1] ?? '';

    $ctypeRaw = inbound_header_value($head, 'Content-Type'); // original case (boundary is case-sensitive)
    $ctype    = strtolower($ctypeRaw);
    $cte      = strtolower(trim(inbound_header_value($head, 'Content-Transfer-Encoding')));

    if (str_starts_with($ctype, 'multipart/')) {
        if (!preg_match('/boundary="?([^";\s]+)"?/i', $ctypeRaw, $bm)) return '';
        $boundary = $bm[1];
        $chunks = preg_split('/\n--' . preg_quote($boundary, '/') . '(?:--)?\n?/', $body);
        $htmlFallback = '';
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk, "\n");
            if ($chunk === '') continue;
            $subCtype = strtolower(inbound_header_value(
                (preg_split("/\n\n/", $chunk, 2)[0] ?? ''), 'Content-Type'));
            $got = inbound_mime_first_text($chunk);
            if ($got === '') continue;
            if (str_starts_with($subCtype, 'text/plain')) return $got; // best case
            if ($htmlFallback === '' && str_starts_with($subCtype, 'text/'))
                $htmlFallback = $got;
            if (str_starts_with($subCtype, 'multipart/')) return $got;
        }
        return $htmlFallback;
    }

    $decoded = inbound_decode_body($body, $cte);
    $decoded = inbound_to_utf8($decoded, $ctype);

    if (str_starts_with($ctype, 'text/html')) {
        $decoded = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $decoded);
        $decoded = preg_replace('/<br\s*\/?>/i', "\n", $decoded);
        $decoded = preg_replace('/<\/p>/i', "\n\n", $decoded);
        $decoded = html_entity_decode(strip_tags($decoded), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return trim($decoded);
}

/** Read one header value out of a header block (case-insensitive, unfolds continuation lines). */
function inbound_header_value(string $head, string $name): string {
    // Unfold RFC-822 folded headers (continuation lines start with whitespace).
    $head = preg_replace('/\n[ \t]+/', ' ', $head);
    foreach (explode("\n", $head) as $line) {
        if (preg_match('/^' . preg_quote($name, '/') . '\s*:\s*(.*)$/i', $line, $m)) {
            return trim($m[1]);
        }
    }
    return '';
}

/** Decode a body by its Content-Transfer-Encoding. */
function inbound_decode_body(string $body, string $cte): string {
    return match ($cte) {
        'base64'           => (string) base64_decode($body),
        'quoted-printable' => quoted_printable_decode($body),
        default            => $body, // 7bit / 8bit / binary / none
    };
}

/** Convert a decoded body to UTF-8 using the charset in its Content-Type, if any. */
function inbound_to_utf8(string $s, string $ctype): string {
    if (!preg_match('/charset="?([^";\s]+)"?/i', $ctype, $m)) return $s;
    $charset = strtoupper($m[1]);
    if ($charset === 'UTF-8' || $charset === 'US-ASCII' || $charset === 'ASCII') return $s;
    if (function_exists('mb_convert_encoding')) {
        $out = @mb_convert_encoding($s, 'UTF-8', $charset);
        if ($out !== false && $out !== '') return $out;
    }
    return $s;
}

/**
 * Trim the quoted history of our original email off the bottom of a reply, so the
 * thread stores just what the guest wrote. Cuts at the first recognised reply
 * separator (Gmail "On … wrote:", Outlook "-----Original Message-----" / "From:"
 * blocks / underscore rule) or a run of ">"-quoted lines. Best-effort — when no
 * separator is found the whole message is kept, which is the safe failure.
 */
function inbound_strip_quoted(string $text): string {
    $lines = explode("\n", $text);
    $cut   = count($lines);

    for ($i = 0; $i < count($lines); $i++) {
        $line = trim($lines[$i]);

        // Gmail / Apple Mail attribution, possibly wrapped onto the next line.
        if (preg_match('/^On .+ wrote:$/', $line) ||
            (preg_match('/^On .+/', $line) && isset($lines[$i + 1]) && preg_match('/wrote:$/', trim($lines[$i + 1])))) {
            $cut = $i; break;
        }
        // Outlook original-message dividers.
        if (preg_match('/^-{2,}\s*Original Message\s*-{2,}$/i', $line) ||
            preg_match('/^_{5,}$/', $line)) {
            $cut = $i; break;
        }
        // Outlook header block ("From: … Sent: … To: … Subject: …").
        if (preg_match('/^From:\s.+@/i', $line) && $i > 0) {
            $cut = $i; break;
        }
        // A block of ">"-quoted lines with nothing but quotes/blank after it.
        if (str_starts_with($line, '>')) {
            $rest = array_slice($lines, $i);
            $onlyQuote = true;
            foreach ($rest as $r) {
                $r = trim($r);
                if ($r !== '' && !str_starts_with($r, '>')) { $onlyQuote = false; break; }
            }
            if ($onlyQuote) { $cut = $i; break; }
        }
    }

    $kept = trim(implode("\n", array_slice($lines, 0, $cut)));
    return $kept !== '' ? $kept : trim($text);
}

/* ------------------------------------------------------------------ *
 *  Small parsing helpers for the SES notification
 * ------------------------------------------------------------------ */

/**
 * Pull the bare "user@host" out of a header value that may be
 * "Name <user@host>" or a raw address. Returns '' if none found.
 */
function inbound_address_only(string $value): string {
    if (preg_match('/<([^>]+)>/', $value, $m)) return strtolower(trim($m[1]));
    if (preg_match('/[^\s<>@]+@[^\s<>@]+/', $value, $m)) return strtolower(trim($m[0]));
    return '';
}

/**
 * Decide whether the raw SES `content` field is base64 or already plain MIME.
 * The SNS action's default encoding is UTF-8 (plain), but it can be BASE64.
 * Heuristic: if the first non-blank line already looks like a MIME header
 * ("Word: …" or "Received: …"), treat it as raw; otherwise base64-decode.
 */
function inbound_normalise_content(string $content): string {
    $firstLine = '';
    foreach (preg_split("/\r?\n/", $content) as $l) {
        if (trim($l) !== '') { $firstLine = $l; break; }
    }
    if (preg_match('/^[A-Za-z][A-Za-z0-9-]*:\s/', $firstLine)) return $content; // looks like headers
    $decoded = base64_decode($content, true);
    return ($decoded !== false && $decoded !== '') ? $decoded : $content;
}
