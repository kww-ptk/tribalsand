<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail-log.php';   // mail_send() — the ONE send path + the send log

// Every email below is built here and handed to mail_send() with a stable
// template key (see email_registry() in includes/email-templates.php). The
// subject / heading / intro / footer note come from email_field(), so the owner
// can reword them in Admin → Emails; the detail tables, buttons and links stay
// code-rendered. Never call _dispatch_mail() from anywhere but mail_send().

/** The public site URL, no trailing slash. */
function _mail_site(): string {
    $env = parse_env();
    return rtrim($env['SITE_URL'] ?? $env['APP_URL'] ?? 'https://tribalsand.com', '/');
}

// ── New lead alert (staff) ───────────────────────────────────────

function send_notification(array $sub, array $ctx = []): void {
    $site = _mail_site();
    $type = ucfirst($sub['type'] ?? 'enquiry');
    $vars = [
        'type'       => $type,
        'guest_name' => (string)($sub['guest_name'] ?? 'Guest'),
        'room_name'  => (string)($sub['room_name'] ?? ''),
        'date'       => date('d M Y', strtotime($sub['created_at'] ?? 'now')),
    ];
    $guestEmail = trim((string)($sub['guest_email'] ?? ''));

    mail_send('staff_new_lead', [
        'to'       => email_staff_address(),
        'subject'  => email_field('staff_new_lead', 'subject', $vars),
        'text'     => build_email_body($sub, $site),
        'html'     => _notify_html($sub, $site, $vars),
        'reply_to' => filter_var($guestEmail, FILTER_VALIDATE_EMAIL) ? $guestEmail : email_staff_address(),
    ], $ctx + ['submission_id' => (int)($sub['id'] ?? 0) ?: null]);
}

function build_email_body(array $sub, string $site_url): string {
    $lines = [];
    $lines[] = strtoupper($sub['type'] ?? 'ENQUIRY') . ' SUBMISSION';
    $lines[] = str_repeat('-', 40);
    $lines[] = 'Name:    ' . ($sub['guest_name']  ?? '');
    $lines[] = 'Email:   ' . ($sub['guest_email'] ?? '');
    $lines[] = 'Phone:   ' . ($sub['guest_phone'] ?? '');

    if (!empty($sub['room_name']))      $lines[] = 'Room:    ' . $sub['room_name'];
    if (!empty($sub['check_in']))       $lines[] = 'Check-in:  ' . $sub['check_in'];
    if (!empty($sub['check_out']))      $lines[] = 'Check-out: ' . $sub['check_out'];
    if (!empty($sub['guests_adults']))  $lines[] = 'Adults:  ' . $sub['guests_adults'];
    if (!empty($sub['guests_children'])) $lines[] = 'Children: ' . $sub['guests_children'];

    $lines[] = '';
    $lines[] = 'Message:';
    $lines[] = $sub['message'] ?? '';
    $lines[] = '';
    $lines[] = str_repeat('-', 40);
    $lines[] = 'TRACKING';
    $lines[] = 'Source page: ' . ($sub['source_page'] ?? '');
    $lines[] = 'Referrer:    ' . ($sub['referrer']    ?? '');
    $lines[] = 'UTM source:  ' . ($sub['utm_source']  ?? '');
    $lines[] = 'UTM medium:  ' . ($sub['utm_medium']  ?? '');
    $lines[] = 'UTM campaign:' . ($sub['utm_campaign'] ?? '');
    $lines[] = '';

    if (!empty($sub['id'])) {
        $lines[] = 'View in dashboard: ' . $site_url . '/admin/submission-view.php?id=' . $sub['id'];
    }

    return implode("\n", $lines);
}

/** Designed HTML for the staff enquiry notification (all enquiry types funnel here). */
function _notify_html(array $sub, string $site, array $vars = []): string {
    $type = ucfirst($sub['type'] ?? 'enquiry');
    $vars += ['type' => $type, 'guest_name' => (string)($sub['guest_name'] ?? ''), 'room_name' => (string)($sub['room_name'] ?? ''), 'date' => date('d M Y')];
    $rows = [
        ['Name',         $sub['guest_name']  ?? ''],
        ['Email',        $sub['guest_email'] ?? ''],
        ['Phone',        $sub['guest_phone'] ?? ''],
        ['Villa / Room', $sub['room_name']   ?? ''],
        ['Check-in',     $sub['check_in']    ?? ''],
        ['Check-out',    $sub['check_out']   ?? ''],
        ['Adults',       !empty($sub['guests_adults'])   ? $sub['guests_adults']   : ''],
        ['Children',     !empty($sub['guests_children']) ? $sub['guests_children'] : ''],
    ];
    $track = [
        ['Source page',  $sub['source_page']  ?? ''],
        ['Referrer',     $sub['referrer']     ?? ''],
        ['UTM source',   $sub['utm_source']   ?? ''],
        ['UTM medium',   $sub['utm_medium']   ?? ''],
        ['UTM campaign', $sub['utm_campaign'] ?? ''],
    ];
    $btn = !empty($sub['id'])
        ? _email_button('View in dashboard', rtrim($site, '/') . '/admin/submission-view.php?id=' . (int)$sub['id'])
        : '';
    $inner = _email_lead_rich(email_field('staff_new_lead', 'intro', $vars))
        . _email_detail_block($rows, $type . ' details')
        . _email_message_block((string)($sub['message'] ?? ''), 'Message')
        . _email_detail_block($track, 'Tracking')
        . $btn;
    return _email_shell(email_field('staff_new_lead', 'heading', $vars), $inner, $site);
}

// ── Transport ────────────────────────────────────────────────────

/** Send via the Resend API (dormant Render-era path — never set RESEND_API_KEY on AWS; it would bypass SES). */
function send_resend(string $to, string $subject, string $text, string $from, string $reply_to, string $api_key, string $html = '', ?string &$messageId = null): bool {
    $body = [
        'from'     => $from,
        'to'       => [$to],
        'reply_to' => $reply_to ?: null,
        'subject'  => $subject,
        'text'     => $text,
    ];
    if ($html !== '') $body['html'] = $html;
    $payload = json_encode($body);

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload),
        ]),
        'content'       => $payload,
        'ignore_errors' => true,
    ]]);

    $result = @file_get_contents('https://api.resend.com/emails', false, $ctx);
    $hdrs   = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? null);
    $status = (isset($hdrs[0]) && preg_match('~\s(\d{3})\s~', $hdrs[0], $m)) ? (int)$m[1] : 0;

    if ($status !== 200 && $status !== 201) {
        log_mail_error("Resend API error {$status}: {$result}");
        return false;
    }
    $j = json_decode((string)$result, true);
    $messageId = is_array($j) ? (string)($j['id'] ?? '') : '';
    return true;
}

/**
 * Send via SMTP — implemented for Amazon SES (also works with any SMTP relay).
 * STARTTLS on 587 (SMTP_SECURITY=tls, default) or implicit TLS on 465 (=ssl),
 * AUTH LOGIN with SES SMTP credentials. Builds a multipart/alternative message
 * so the branded HTML part (when present) and the plain-text fallback both go
 * out. Any failure logs and, as a last resort, falls back to PHP mail().
 *
 * $messageId receives the SES Message-ID from the final "250 Ok <id>" reply —
 * the id SES delivery / bounce events carry (api/ses-events.php matches on it).
 * SES_CONFIGURATION_SET (optional) adds X-SES-CONFIGURATION-SET so SES publishes
 * those events (docs/email-events-setup.md).
 *
 * Env: SMTP_HOST (or derived email-smtp.<S3_REGION>.amazonaws.com), SMTP_PORT,
 *      SMTP_USER, SMTP_PASS, SMTP_SECURITY (tls|ssl), SMTP_EHLO, MAIL_FROM,
 *      SES_CONFIGURATION_SET.
 */
function send_smtp(string $to, string $subject, string $body, string $headers, array $env, string $html = '', ?string &$messageId = null): bool {
    $host = trim((string)($env['SMTP_HOST'] ?? ''));
    if ($host === '' && !empty($env['S3_REGION'])) {
        $host = "email-smtp.{$env['S3_REGION']}.amazonaws.com";   // SES default endpoint for the region
    }
    $port   = (int)($env['SMTP_PORT'] ?? 587);
    $user   = trim((string)($env['SMTP_USER'] ?? ''));
    $pass   = (string)($env['SMTP_PASS'] ?? '');
    $secure = strtolower(trim((string)($env['SMTP_SECURITY'] ?? 'tls')));   // tls = STARTTLS, ssl = implicit

    if ($host === '' || $user === '' || $pass === '') {
        log_mail_error('SMTP not configured (need SMTP_HOST/SMTP_USER/SMTP_PASS). Falling back to mail().');
        $ok = @mail($to, $subject, $body, $headers);
        if (!$ok) log_mail_error("mail() fallback failed for: {$to}");
        return $ok;
    }

    $fromHeader = _smtp_header_val($headers, 'From') ?: (string)($env['MAIL_FROM'] ?? 'noreply@tribalsand.com');
    $replyTo    = _smtp_header_val($headers, 'Reply-To');
    $fromAddr   = _smtp_addr($fromHeader);
    $toAddr     = _smtp_addr($to);
    $eol        = "\r\n";

    // Assemble the message (headers + body).
    $h = [
        'From: ' . $fromHeader,
        'To: ' . $to,
        'Subject: ' . _smtp_encode_subject($subject),
    ];
    if ($replyTo !== '') $h[] = 'Reply-To: ' . $replyTo;
    $h[] = 'Date: ' . date('r');
    $h[] = 'MIME-Version: 1.0';
    $dom  = preg_replace('~^.*@~', '', $fromAddr) ?: 'tribalsand.com';
    $h[]  = 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $dom . '>';
    $cfgSet = trim((string)($env['SES_CONFIGURATION_SET'] ?? ''));
    if ($cfgSet !== '' && preg_match('~^[A-Za-z0-9_-]{1,64}$~', $cfgSet)) $h[] = 'X-SES-CONFIGURATION-SET: ' . $cfgSet;

    if ($html !== '') {
        $boundary = 'bnd_' . bin2hex(random_bytes(12));
        $h[]  = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $msg  = '--' . $boundary . $eol
              . 'Content-Type: text/plain; charset=UTF-8' . $eol
              . 'Content-Transfer-Encoding: base64' . $eol . $eol
              . chunk_split(base64_encode($body)) . $eol
              . '--' . $boundary . $eol
              . 'Content-Type: text/html; charset=UTF-8' . $eol
              . 'Content-Transfer-Encoding: base64' . $eol . $eol
              . chunk_split(base64_encode($html)) . $eol
              . '--' . $boundary . '--' . $eol;
    } else {
        $h[] = 'Content-Type: text/plain; charset=UTF-8';
        $h[] = 'Content-Transfer-Encoding: base64';
        $msg = chunk_split(base64_encode($body));
    }
    $data = implode($eol, $h) . $eol . $eol . $msg;
    $data = preg_replace('~^\.~m', '..', $data);   // dot-stuffing (RFC 5321 §4.5.2)

    // Open the connection.
    $transport = ($secure === 'ssl') ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";
    $fp = @stream_socket_client($transport, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, stream_context_create());
    if (!$fp) { log_mail_error("SMTP connect failed to {$host}:{$port} — {$errno} {$errstr}"); return false; }
    stream_set_timeout($fp, 20);

    $last = '';
    $read = function () use ($fp, &$last): int {
        $line = '';
        do { $line = fgets($fp, 515); if ($line === false) return 0; }
        while (strlen($line) >= 4 && $line[3] === '-');   // consume multiline replies
        $last = rtrim($line);
        return (int)substr($line, 0, 3);
    };
    $send = function (string $c) use ($fp): void { fwrite($fp, $c . "\r\n"); };
    $fail = function (string $why) use ($fp, &$last): bool {
        log_mail_error($why . ($last !== '' ? " ({$last})" : ''));
        @fwrite($fp, "QUIT\r\n"); fclose($fp); return false;
    };

    if ($read() !== 220) { return $fail('SMTP: no 220 greeting'); }
    $ehlo = 'EHLO ' . (string)($env['SMTP_EHLO'] ?? $dom);
    $send($ehlo); if ($read() !== 250) { return $fail('SMTP: EHLO rejected'); }

    if ($secure === 'tls') {
        $send('STARTTLS'); if ($read() !== 220) { return $fail('SMTP: STARTTLS rejected'); }
        $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (!@stream_socket_enable_crypto($fp, true, $crypto)) { return $fail('SMTP: TLS handshake failed'); }
        $send($ehlo); if ($read() !== 250) { return $fail('SMTP: EHLO after STARTTLS rejected'); }
    }

    $send('AUTH LOGIN');        if ($read() !== 334) { return $fail('SMTP: AUTH LOGIN rejected'); }
    $send(base64_encode($user)); if ($read() !== 334) { return $fail('SMTP: username rejected'); }
    $send(base64_encode($pass)); if ($read() !== 235) { return $fail('SMTP: authentication failed'); }

    $send("MAIL FROM:<{$fromAddr}>"); if ($read() !== 250) { return $fail('SMTP: MAIL FROM rejected'); }
    $send("RCPT TO:<{$toAddr}>");     if (!in_array($read(), [250, 251], true)) { return $fail('SMTP: RCPT TO rejected'); }
    $send('DATA');                    if ($read() !== 354) { return $fail('SMTP: DATA rejected'); }

    fwrite($fp, $data . "\r\n.\r\n");
    if ($read() !== 250) { return $fail('SMTP: message not accepted'); }
    $messageId = _smtp_ses_message_id($last);

    $send('QUIT');
    fclose($fp);
    return true;
}

/** The SES Message-ID from its "250 Ok <id>" DATA reply ('' for other servers). Pure. */
function _smtp_ses_message_id(string $reply): string {
    return preg_match('~^250[ -]Ok\s+([A-Za-z0-9._@-]+)~i', trim($reply), $m) ? $m[1] : '';
}

/** Pull a single header's value out of a CRLF header block. */
function _smtp_header_val(string $headers, string $name): string {
    if (preg_match('~^' . preg_quote($name, '~') . ':\s*(.+)$~mi', $headers, $m)) return trim($m[1]);
    return '';
}

/** Bare email address from a "Name <addr>" or plain "addr" string. */
function _smtp_addr(string $s): string {
    if (preg_match('~<([^>]+)>~', $s, $m)) return trim($m[1]);
    return trim($s);
}

/** RFC 2047 encode a subject only when it contains non-ASCII bytes. */
function _smtp_encode_subject(string $s): string {
    if (preg_match('~[\x80-\xFF]~', $s)) return '=?UTF-8?B?' . base64_encode($s) . '?=';
    return $s;
}

// ── Guest acknowledgement (auto-reply to the customer/sender) ────
// $a: guest_name, guest_email, kind (enquiry|hold|contact|agency) plus any of
//     room_name / tour_name / check_in / check_out / agency_name / subject / message,
//     and submission_id / hold_id for the send log.
function send_guest_acknowledgement(array $a, array $ctx = []): void {
    $to    = trim((string)($a['guest_email'] ?? ''));
    $site  = _mail_site();
    $reply = email_guest_reply_to();
    $name  = trim((string)($a['guest_name'] ?? '')) ?: 'Guest';
    $kind  = in_array($a['kind'] ?? '', ['hold', 'contact', 'agency'], true) ? $a['kind'] : 'enquiry';
    $key   = 'ack_' . $kind;

    $vars = [
        'guest_name' => $name,
        'room_name'  => (string)($a['room_name'] ?? ''),
        'check_in'   => (string)($a['check_in'] ?? ''),
        'check_out'  => (string)($a['check_out'] ?? ''),
    ];
    $subject = email_field($key, 'subject', $vars);
    $intro   = email_field($key, 'intro', $vars);
    $heading = email_field($key, 'heading', $vars);
    $footer  = email_field($key, 'footer_note', $vars);

    // Friendly guest-count summary (e.g. "2 adults · 1 child") for enquiry/hold acks
    if (isset($a['guests_adults']) || isset($a['guests_children'])) {
        $ga = max(1, (int)($a['guests_adults'] ?? 1));
        $gc = max(0, (int)($a['guests_children'] ?? 0));
        $parts = [$ga . ' ' . ($ga === 1 ? 'adult' : 'adults')];
        if ($gc) $parts[] = $gc . ' ' . ($gc === 1 ? 'child' : 'children');
        $a['guests'] = implode(' · ', $parts);
    }

    $rows = [];
    foreach (['room_name' => 'Villa / Room', 'tour_name' => 'Experience',
              'check_in' => 'Check-in', 'check_out' => 'Check-out',
              'guests' => 'Guests', 'agency_name' => 'Agency', 'subject' => 'Subject',
              'price' => 'Price' /* trade-portal acks only */] as $k => $label) {
        if (empty($a[$k])) continue;
        $val = (string)$a[$k];
        if (($k === 'check_in' || $k === 'check_out') && ($ts = strtotime($val))) {
            $val = date('D, j M Y', $ts);   // e.g. "Fri, 22 Aug 2026"
        }
        $rows[] = [$label, $val];
    }

    $manage_url  = '';
    $access_code = trim((string)($a['access_code'] ?? ''));
    if ($kind === 'hold' && !empty($a['hold_id'])) {
        require_once __DIR__ . '/booking.php';
        $manage_url = make_manage_url((int)$a['hold_id']);
    }

    $tl = ["Dear {$name},", '', _email_plain($intro), ''];
    if ($rows) {
        $tl[] = 'YOUR DETAILS';
        foreach ($rows as [$k, $v]) $tl[] = "  {$k}: {$v}";
        $tl[] = '';
    }
    if (!empty($a['message'])) {
        $tl[] = 'Your message:';
        $tl[] = (string)$a['message'];
        $tl[] = '';
    }
    if ($manage_url) {
        $tl[] = 'MANAGE YOUR BOOKING';
        $tl[] = "  View status & add tours/transfers: {$manage_url}";
        if ($access_code) $tl[] = "  Your booking code: {$access_code}";
        $tl[] = '';
    }
    if ($footer !== '') { $tl[] = _email_plain($footer); $tl[] = ''; }
    $tl[] = 'Warm regards,';
    $tl[] = 'Tribal Sand';
    $tl[] = 'Kenya’s North Coast';
    if ($site) $tl[] = $site;

    $html = _guest_ack_html([
        'name'        => $name,
        'heading'     => $heading,
        'intro'       => $intro,
        'footer'      => $footer,
        'rows'        => $rows,
        'message'     => (string)($a['message'] ?? ''),
        'site'        => $site,
        'manage_url'  => $manage_url,
        'access_code' => $access_code,
    ]);

    mail_send($key, ['to' => $to, 'subject' => $subject, 'text' => implode("\n", $tl), 'html' => $html, 'reply_to' => $reply],
        $ctx + ['hold_id' => (int)($a['hold_id'] ?? 0) ?: null, 'submission_id' => (int)($a['submission_id'] ?? 0) ?: null]);
}

function _guest_ack_html(array $d): string {
    $esc  = fn(string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $site = rtrim($d['site'] ?? '', '/');

    $detail_rows = '';
    foreach ($d['rows'] as [$k, $v]) {
        $detail_rows .= '<tr>'
            . '<td style="padding:7px 0;color:#777;font-size:13px;width:120px;vertical-align:top">' . $esc($k) . '</td>'
            . '<td style="padding:7px 0;font-size:14px;color:#222;font-weight:600">' . $esc($v) . '</td>'
            . '</tr>';
    }
    $detail_block = $detail_rows
        ? '<div style="background:#f4f0e8;border-radius:6px;padding:18px 22px;margin:20px 0">'
            . '<p style="margin:0 0 10px;font-size:12px;font-weight:700;text-transform:uppercase;color:#1E5C6B;letter-spacing:.5px">Your details</p>'
            . '<table style="width:100%;border-collapse:collapse">' . $detail_rows . '</table>'
          . '</div>'
        : '';

    $message_block = '';
    if (($d['message'] ?? '') !== '') {
        $message_block = '<div style="margin:20px 0">'
            . '<p style="margin:0 0 8px;font-size:12px;font-weight:700;text-transform:uppercase;color:#777;letter-spacing:.5px">Your message</p>'
            . '<div style="background:#f9fafb;border-left:3px solid #B8965A;padding:14px 18px;border-radius:0 4px 4px 0;font-size:14px;color:#333;line-height:1.7">'
            . nl2br($esc($d['message']))
            . '</div></div>';
    }

    $manage = '';
    if (!empty($d['manage_url'])) {
        $codeHtml = !empty($d['access_code'])
            ? '<p style="margin:10px 0 0;font-size:14px;color:#555">Booking code: <strong style="letter-spacing:2px">'
              . $esc((string)$d['access_code']) . '</strong></p>'
            : '';
        $manage =
            '<div style="text-align:center;margin:24px 0">'
          . '<a href="' . $esc((string)$d['manage_url']) . '" '
          . 'style="display:inline-block;background:#102F3A;color:#fff;text-decoration:none;'
          . 'padding:12px 24px;border-radius:8px;font-weight:600">Manage your booking</a>'
          . $codeHtml . '</div>';
    }

    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f0f4f5;font-family:Arial,Helvetica,sans-serif">'
        . '<div style="max-width:600px;margin:32px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)">'
          . '<div style="background:#102F3A;padding:24px 32px">'
            . '<h1 style="margin:0;color:#fff;font-size:20px;font-weight:700">' . $esc((string)($d['heading'] ?? 'Thank you for contacting us')) . '</h1>'
            . '<p style="margin:6px 0 0;color:#B8965A;font-size:14px">Tribal Sand &mdash; Kenya’s North Coast</p>'
          . '</div>'
          . '<div style="padding:32px">'
            . '<p style="margin:0 0 18px;font-size:15px">Dear <strong>' . $esc($d['name']) . '</strong>,</p>'
            . '<p style="margin:0 0 4px;font-size:15px;line-height:1.6">' . _email_rich((string)$d['intro']) . '</p>'
            . $detail_block
            . $message_block
            . $manage
            . _email_note((string)($d['footer'] ?? ''))
            . '<p style="font-size:14px;margin:24px 0 0">Warm regards,<br><strong>Tribal Sand</strong></p>'
          . '</div>'
          . '<div style="background:#f9fafb;padding:16px 32px;text-align:center;font-size:12px;color:#aaa">'
            . '<a href="' . $esc($site ?: '#') . '" style="color:#1E5C6B;text-decoration:none">tribalsand.com</a>'
          . '</div>'
        . '</div></body></html>';
}

// ── Trip Builder itinerary emails ───────────────────────────────
// Rich, dedicated guest acknowledgement + staff notification for the
// multi-step Trip Builder. $d is the full JSON payload posted to
// api/trip-builder.php: guest{}, trip{}, departure{}, special{}, itinerary[].

function _tb_prop_name(string $slug): string {
    $m = [
        'zuri'     => 'Zuri Boutique Hotel · Watamu',
        'mayakobe' => 'Maya Kobe · Kilifi',
        'amani'    => 'My Amani Villa · Vipingo',
        'enkare'   => 'Enkare Villa · Kilifi',
        'sandbox'  => 'Sandbox Villa · Kilifi',
        'ext'      => 'External accommodation',
    ];
    return $m[$slug] ?? ($slug !== '' ? $slug : '—');
}

function _tb_nights(array $t): int {
    $a = strtotime((string)($t['arrDate'] ?? ''));
    $b = strtotime((string)($t['depDate'] ?? ''));
    return ($a && $b && $b > $a) ? (int)round(($b - $a) / 86400) : 0;
}

function _tb_party(array $t): string {
    $ad = (int)($t['adults'] ?? 0); $ch = (int)($t['children'] ?? 0); $inf = (int)($t['infants'] ?? 0);
    $p = [max(1, $ad) . ' adult' . ($ad === 1 ? '' : 's')];
    if ($ch)  $p[] = $ch . ' child' . ($ch === 1 ? '' : 'ren');
    if ($inf) $p[] = $inf . ' infant' . ($inf === 1 ? '' : 's');
    return implode(' · ', $p);
}

/** $only = 'guest' | 'staff' sends just that half (previews); '' sends both. */
function send_trip_builder_emails(array $d, int $id, string $only = ''): void {
    $site  = _mail_site();
    $g     = $d['guest'] ?? [];
    $email = trim((string)($g['email'] ?? ''));
    $name  = trim(((string)($g['firstName'] ?? '')) . ' ' . ((string)($g['lastName'] ?? '')));
    $ref   = 'TSB-' . $id;
    $ctx   = ['submission_id' => $id ?: null];

    // Guest acknowledgement
    if ($only !== 'staff') {
        $vars = ['guest_name' => trim((string)($g['firstName'] ?? '')) ?: 'Guest', 'reference' => $ref];
        mail_send('trip_builder_guest', [
            'to'       => $email,
            'subject'  => email_field('trip_builder_guest', 'subject', $vars),
            'text'     => _trip_builder_text($d, 'guest', $ref, $site),
            'html'     => _trip_builder_html($d, 'guest', $ref, $site),
            'reply_to' => email_guest_reply_to(),
        ], $ctx);
    }

    // Staff notification
    if ($only !== 'guest') {
        $vars = ['guest_name' => $name !== '' ? $name : 'Guest', 'property_name' => _tb_prop_name((string)($d['trip']['prop'] ?? '')), 'reference' => $ref];
        mail_send('trip_builder_staff', [
            'to'       => email_staff_address(),
            'subject'  => email_field('trip_builder_staff', 'subject', $vars),
            'text'     => _trip_builder_text($d, 'staff', $ref, $site, $id),
            'html'     => _trip_builder_html($d, 'staff', $ref, $site, $id),
            'reply_to' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : email_staff_address(),
        ], $ctx);
    }
}

function _trip_builder_html(array $d, string $audience, string $ref, string $site, int $id = 0): string {
    $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $t = $d['trip'] ?? []; $dep = $d['departure'] ?? []; $sp = $d['special'] ?? []; $g = $d['guest'] ?? [];
    $amL = ['flight'=>'International Flight','domestic'=>'Domestic Flight','road'=>'Road / Self-Drive','charter'=>'Charter / Private','here'=>'Already in Kenya'];
    $tfL = ['yes'=>'Arranged by Tribal Sand','no'=>'Guest will manage','tbc'=>'To confirm'];

    $block = function (string $title, array $rows) use ($esc): string {
        $body = '';
        foreach ($rows as [$k, $v]) {
            if ($v === '' || $v === null) continue;
            $body .= '<tr><td style="padding:7px 0;color:#A89880;font-size:13px;width:130px;vertical-align:top;font-family:Arial,Helvetica,sans-serif">' . $esc($k) . '</td>'
                   . '<td style="padding:7px 0;font-size:14px;color:#141412;font-weight:600;font-family:Arial,Helvetica,sans-serif">' . $esc($v) . '</td></tr>';
        }
        if ($body === '') return '';
        return '<div style="background:#f4f0e8;border-radius:6px;padding:18px 22px;margin:0 0 16px">'
             . '<p style="margin:0 0 8px;font-size:12px;font-weight:700;text-transform:uppercase;color:#1E5C6B;letter-spacing:.5px;font-family:Arial,Helvetica,sans-serif">' . $esc($title) . '</p>'
             . '<table style="width:100%;border-collapse:collapse">' . $body . '</table></div>';
    };

    $fmtDate = fn($v) => ($v && ($ts = strtotime((string)$v))) ? date('D, j M Y', $ts) : '';

    $stay = $block('Your stay', [
        ['Property',  _tb_prop_name((string)($t['prop'] ?? ''))],
        ['Arrival',   $fmtDate($t['arrDate'] ?? '')],
        ['Departure', $fmtDate($t['depDate'] ?? '')],
        ['Duration',  _tb_nights($t) ? (_tb_nights($t) . ' night' . (_tb_nights($t) === 1 ? '' : 's')) : ''],
        ['Party',     _tb_party($t)],
        ['Trip type', $t['purpose'] ?? ''],
    ]);
    $arr = $block('Arrival', [
        ['Arriving by',  $amL[$t['arrMode'] ?? ''] ?? ($t['arrMode'] ?? '')],
        ['Flight',       $t['flightNum'] ?? ''],
        ['Airport',      $t['airport'] ?? ''],
        ['Landing time', $t['arrTime'] ?? ''],
        ['Flying from',  $t['fromCity'] ?? ''],
        ['Transfer',     $tfL[$t['transfer'] ?? ''] ?? ''],
    ]);
    $depB = $block('Departure', [
        ['Flight',         $dep['flight'] ?? ''],
        ['Airport',        $dep['airport'] ?? ''],
        ['Departure time', $dep['time'] ?? ''],
        ['Transfer',       $tfL[$dep['transfer'] ?? ''] ?? ''],
        ['Final requests', $dep['notes'] ?? ''],
    ]);

    $itin = '';
    foreach (($d['itinerary'] ?? []) as $day) {
        $slots = $day['slots'] ?? [];
        if (!$slots) continue;
        $itin .= '<p style="margin:14px 0 4px;font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:#B8965A;font-family:Arial,Helvetica,sans-serif">' . $esc($day['label'] ?? '') . '</p>';
        foreach ($slots as $s) {
            $tm = ($s['time'] ?? '') ? ' <span style="color:#B8965A">· ' . $esc($s['time']) . '</span>' : '';
            $itin .= '<p style="margin:0 0 3px 12px;font-size:13px;color:#6B6050;font-family:Arial,Helvetica,sans-serif">' . $esc($s['name'] ?? '') . $tm . '</p>';
        }
    }
    $itinBlock = $itin
        ? '<div style="background:#FAF8F4;border-radius:6px;padding:18px 22px;margin:0 0 16px"><p style="margin:0 0 4px;font-size:12px;font-weight:700;text-transform:uppercase;color:#1E5C6B;letter-spacing:.5px;font-family:Arial,Helvetica,sans-serif">Your itinerary</p>' . $itin . '</div>'
        : '';

    $occ = array_values(array_filter((array)($sp['occasions'] ?? []), fn($o) => $o && $o !== 'none'));
    $special = $block('Special touches', [
        ['Occasions',     $occ ? implode(', ', $occ) : ''],
        ['Dietary',       ($sp['diet'] ?? []) ? implode(', ', (array)$sp['diet']) : ''],
        ['Accessibility', ($sp['mobility'] ?? []) ? implode(', ', (array)$sp['mobility']) : ''],
        ['Trip pace',     $sp['pace'] ?? ''],
        ['Notes',         $sp['extraNotes'] ?? ''],
    ]);

    if ($audience === 'staff') {
        $headTitle = 'New Trip Builder Request';
        $lead = '<p style="margin:0 0 18px;font-size:15px;color:#6B6050;line-height:1.7;font-family:Arial,Helvetica,sans-serif">A guest has submitted a bespoke itinerary through the Trip Builder. Full details below.</p>';
        $extra = $block('Guest', [
            ['Name',        trim(((string)($g['firstName'] ?? '')) . ' ' . ((string)($g['lastName'] ?? '')))],
            ['Email',       $g['email'] ?? ''],
            ['Phone',       $g['phone'] ?? ''],
            ['Nationality', $g['nationality'] ?? ''],
            ['Residence',   $g['country'] ?? ''],
        ]);
        $footerLink = $id
            ? '<div style="text-align:center;margin:8px 0 4px"><a href="' . $esc($site . '/admin/submission-view.php?id=' . $id) . '" style="display:inline-block;background:#102F3A;color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;font-family:Arial,Helvetica,sans-serif">Open in dashboard</a></div>'
            : '';
    } else {
        $headTitle = 'Your Trip Plan';
        $first = trim((string)($g['firstName'] ?? '')) ?: 'Guest';
        $intro = email_field('trip_builder_guest', 'intro', ['guest_name' => $first, 'reference' => $ref]);
        $lead = '<p style="margin:0 0 14px;font-size:15px;font-family:Arial,Helvetica,sans-serif">Dear <strong>' . $esc($first) . '</strong>,</p>'
              . '<p style="margin:0 0 8px;font-size:15px;color:#6B6050;line-height:1.75;font-family:Arial,Helvetica,sans-serif">' . _email_rich($intro) . '</p>';
        $extra = '';
        $footerLink = '';
    }

    $refBar = '<table width="100%" cellpadding="0" cellspacing="0" style="background:#F2E8D6"><tr>'
            . '<td style="padding:12px 32px;font-size:11px;letter-spacing:.22em;color:#B8965A;text-transform:uppercase;font-family:Arial,Helvetica,sans-serif">Reference</td>'
            . '<td align="right" style="padding:12px 32px;font-size:14px;color:#141412;font-family:Arial,Helvetica,sans-serif;font-weight:700">' . $esc($ref) . '</td>'
            . '</tr></table>';

    $contactLine = $audience === 'guest'
        ? ' or write to <a href="mailto:' . $esc(email_guest_reply_to()) . '" style="color:#1E5C6B">' . $esc(email_guest_reply_to()) . '</a>'
        : '';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f0f4f5;font-family:Arial,Helvetica,sans-serif">'
        . '<div style="max-width:600px;margin:32px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)">'
          . '<div style="background:#102F3A;padding:24px 32px">'
            . '<h1 style="margin:0;color:#fff;font-size:20px;font-weight:700">' . $esc($headTitle) . '</h1>'
            . '<p style="margin:6px 0 0;color:#B8965A;font-size:14px">Tribal Sand &mdash; Kenya&rsquo;s North Coast</p>'
          . '</div>'
          . $refBar
          . '<div style="padding:32px">'
            . $lead
            . $extra
            . $stay . $arr . $depB . $itinBlock . $special
            . $footerLink
            . '<p style="font-size:13px;color:#777;line-height:1.6;margin-top:20px;font-family:Arial,Helvetica,sans-serif">Questions? Reply to this email' . $contactLine . '.</p>'
            . ($audience === 'guest' ? '<p style="font-size:14px;margin:20px 0 0;font-family:Arial,Helvetica,sans-serif">Warm regards,<br><strong>Tribal Sand</strong></p>' : '')
          . '</div>'
          . '<div style="background:#f9fafb;padding:16px 32px;text-align:center;font-size:12px;color:#aaa">'
            . '<a href="' . $esc($site ?: '#') . '" style="color:#1E5C6B;text-decoration:none">tribalsand.com</a>'
          . '</div>'
        . '</div></body></html>';
}

function _trip_builder_text(array $d, string $audience, string $ref, string $site, int $id = 0): string {
    $t = $d['trip'] ?? []; $dep = $d['departure'] ?? []; $sp = $d['special'] ?? []; $g = $d['guest'] ?? [];
    $amL = ['flight'=>'International Flight','domestic'=>'Domestic Flight','road'=>'Road / Self-Drive','charter'=>'Charter / Private','here'=>'Already in Kenya'];
    $tfL = ['yes'=>'Arranged','no'=>'Guest will manage','tbc'=>'To confirm'];
    $L = [];
    if ($audience === 'staff') {
        $L[] = 'NEW TRIP BUILDER REQUEST';
    } else {
        $first = trim((string)($g['firstName'] ?? '')) ?: 'Guest';
        $L[] = 'Dear ' . $first . ',';
        $L[] = '';
        $L[] = _email_plain(email_field('trip_builder_guest', 'intro', ['guest_name' => $first, 'reference' => $ref]));
    }
    $L[] = '';
    $L[] = 'Reference: ' . $ref;
    if ($audience === 'staff') {
        $L[] = '';
        $L[] = 'GUEST';
        $L[] = '  Name:  ' . trim(((string)($g['firstName'] ?? '')) . ' ' . ((string)($g['lastName'] ?? '')));
        $L[] = '  Email: ' . ($g['email'] ?? '');
        $L[] = '  Phone: ' . ($g['phone'] ?? '');
        if (!empty($g['nationality'])) $L[] = '  Nationality: ' . $g['nationality'];
        if (!empty($g['country']))     $L[] = '  Residence:   ' . $g['country'];
    }
    $L[] = '';
    $L[] = 'YOUR STAY';
    $L[] = '  Property:  ' . _tb_prop_name((string)($t['prop'] ?? ''));
    $L[] = '  Arrival:   ' . (($t['arrDate'] ?? '') ?: '—');
    $L[] = '  Departure: ' . (($t['depDate'] ?? '') ?: '—');
    $L[] = '  Duration:  ' . _tb_nights($t) . ' nights';
    $L[] = '  Party:     ' . _tb_party($t);
    if (!empty($t['purpose'])) $L[] = '  Trip type: ' . $t['purpose'];
    $L[] = '';
    $L[] = 'ARRIVAL';
    $L[] = '  Mode:     ' . ($amL[$t['arrMode'] ?? ''] ?? (($t['arrMode'] ?? '') ?: '—'));
    if (!empty($t['flightNum'])) $L[] = '  Flight:   ' . $t['flightNum'];
    if (!empty($t['airport']))   $L[] = '  Airport:  ' . $t['airport'];
    if (!empty($t['arrTime']))   $L[] = '  Landing:  ' . $t['arrTime'];
    if (!empty($t['fromCity']))  $L[] = '  From:     ' . $t['fromCity'];
    $L[] = '  Transfer: ' . ($tfL[$t['transfer'] ?? ''] ?? '—');
    $L[] = '';
    $L[] = 'DEPARTURE';
    if (!empty($dep['flight']))  $L[] = '  Flight:   ' . $dep['flight'];
    if (!empty($dep['airport'])) $L[] = '  Airport:  ' . $dep['airport'];
    if (!empty($dep['time']))    $L[] = '  Time:     ' . $dep['time'];
    $L[] = '  Transfer: ' . ($tfL[$dep['transfer'] ?? ''] ?? '—');
    if (!empty($dep['notes']))   $L[] = '  Notes:    ' . $dep['notes'];
    $itinLines = [];
    foreach (($d['itinerary'] ?? []) as $day) {
        $slots = $day['slots'] ?? [];
        if (!$slots) continue;
        $itinLines[] = '  ' . ($day['label'] ?? '');
        foreach ($slots as $s) {
            $itinLines[] = '    - ' . ($s['name'] ?? '') . (($s['time'] ?? '') ? ' (' . $s['time'] . ')' : '');
        }
    }
    if ($itinLines) { $L[] = ''; $L[] = 'ITINERARY'; foreach ($itinLines as $il) $L[] = $il; }
    $occ = array_values(array_filter((array)($sp['occasions'] ?? []), fn($o) => $o && $o !== 'none'));
    $spLines = [];
    if ($occ)                     $spLines[] = '  Occasions: ' . implode(', ', $occ);
    if (!empty($sp['diet']))      $spLines[] = '  Dietary:   ' . implode(', ', (array)$sp['diet']);
    if (!empty($sp['mobility']))  $spLines[] = '  Access:    ' . implode(', ', (array)$sp['mobility']);
    if (!empty($sp['pace']))      $spLines[] = '  Pace:      ' . $sp['pace'];
    if (!empty($sp['extraNotes'])) $spLines[] = '  Notes:     ' . $sp['extraNotes'];
    if ($spLines) { $L[] = ''; $L[] = 'SPECIAL TOUCHES'; foreach ($spLines as $sl) $L[] = $sl; }
    $L[] = '';
    if ($audience === 'staff' && $id) {
        $L[] = 'View in dashboard: ' . $site . '/admin/submission-view.php?id=' . $id;
    } else {
        $L[] = 'Warm regards,';
        $L[] = 'Tribal Sand';
        $L[] = 'Kenya\'s North Coast';
        if ($site) $L[] = $site;
    }
    return implode("\n", $L);
}

// ── Hold notifications ──────────────────────────────────────────

function send_hold_notification(array $hold, array $ctx = []): void {
    require_once __DIR__ . '/booking.php';

    $site    = _mail_site();
    $holdId  = (int)$hold['id'];
    $expires = isset($hold['expires_at']) ? date('d M Y H:i', strtotime($hold['expires_at'])) . ' (UTC+3)' : '24 hours';
    $vars    = ['guest_name' => (string)$hold['guest_name'], 'room_name' => (string)$hold['room_name'],
                'check_in' => (string)$hold['check_in'], 'check_out' => (string)$hold['check_out']];

    // Action URLs (when the token secret is configured). Both land on a
    // confirmation screen (admin/hold-action.php) — nothing happens on the click.
    $confirm_url = $site . '/admin/holds.php';
    $decline_url = $site . '/admin/holds.php';
    $has_tokens  = false;
    $ct = make_hold_token($holdId, 'confirm');
    $dt = make_hold_token($holdId, 'decline');
    if ($ct && $dt) {
        $confirm_url = $site . '/admin/hold-action.php?id=' . $holdId . '&action=confirm&t=' . urlencode($ct);
        $decline_url = $site . '/admin/hold-action.php?id=' . $holdId . '&action=decline&t=' . urlencode($dt);
        $has_tokens  = true;
    }

    // Plain-text fallback (all mail drivers)
    $text_lines = [
        'NEW HOLD REQUEST — 24-HOUR SOFT HOLD',
        str_repeat('-', 40),
        "Guest:     {$hold['guest_name']}",
        "Email:     {$hold['guest_email']}",
        "Room:      {$hold['room_name']} ({$hold['unit_name']})",
        "Check-in:  {$hold['check_in']}",
        "Check-out: {$hold['check_out']}",
        "Expires:   {$expires}",
        '',
    ];
    if ($has_tokens) {
        $text_lines[] = "CONFIRM: {$confirm_url}";
        $text_lines[] = "DECLINE: {$decline_url}";
    } else {
        $text_lines[] = "Manage holds: {$site}/admin/holds.php";
        $text_lines[] = '(Set BOOKING_TOKEN_SECRET in .env to enable one-click confirm/decline buttons.)';
    }

    $html = _hold_notification_html([
        'heading'     => email_field('staff_hold_request', 'heading', $vars),
        'guest_name'  => $hold['guest_name'],
        'guest_email' => $hold['guest_email'],
        'room_name'   => $hold['room_name'],
        'unit_name'   => $hold['unit_name'],
        'check_in'    => $hold['check_in'],
        'check_out'   => $hold['check_out'],
        'expires'     => $expires,
        'confirm_url' => $confirm_url,
        'decline_url' => $decline_url,
        'holds_url'   => $site . '/admin/holds.php',
        'has_tokens'  => $has_tokens,
    ]);

    $guestEmail = trim((string)($hold['guest_email'] ?? ''));
    mail_send('staff_hold_request', [
        'to'       => email_staff_address(),
        'subject'  => email_field('staff_hold_request', 'subject', $vars),
        'text'     => implode("\n", $text_lines),
        'html'     => $html,
        'reply_to' => filter_var($guestEmail, FILTER_VALIDATE_EMAIL) ? $guestEmail : email_staff_address(),
    ], $ctx + ['hold_id' => $holdId ?: null]);
}

function _hold_notification_html(array $d): string {
    $esc = fn(string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $btn = fn(string $url, string $label, string $bg) =>
        '<a href="' . $esc($url) . '" style="background:' . $bg . ';color:#fff;padding:14px 28px;border-radius:6px;'
        . 'text-decoration:none;font-size:15px;font-weight:700;display:inline-block;margin:0 6px;mso-padding-alt:0">'
        . $label . '</a>';

    $action_block = $d['has_tokens']
        ? '<div style="margin:32px 0;text-align:center">'
            . $btn($d['confirm_url'], '&#10003; Confirm Hold', '#16a34a')
            . $btn($d['decline_url'], '&#10007; Decline', '#dc2626')
          . '</div>'
        : '<div style="margin:24px 0;text-align:center">'
            . $btn($d['holds_url'], 'Open Holds &amp; Bookings', '#1E5C6B')
          . '</div>'
          . '<p style="font-size:12px;color:#999;text-align:center">Set BOOKING_TOKEN_SECRET in .env to enable one-click email buttons.</p>';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f0f4f5;font-family:Arial,Helvetica,sans-serif">'
        . '<div style="max-width:600px;margin:32px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)">'
          . '<div style="background:#1E5C6B;padding:24px 32px">'
            . '<h1 style="margin:0;color:#fff;font-size:20px;font-weight:700">' . $esc((string)($d['heading'] ?? 'New Hold Request')) . '</h1>'
            . '<p style="margin:6px 0 0;color:#bcdfe6;font-size:14px">24-hour soft hold &mdash; please confirm or decline</p>'
          . '</div>'
          . '<div style="padding:32px">'
            . '<table style="width:100%;border-collapse:collapse;margin-bottom:8px">'
              . '<tr><td style="padding:8px 0;color:#777;font-size:13px;width:90px;vertical-align:top">Guest</td>'
                  . '<td style="padding:8px 0;font-weight:700">' . $esc($d['guest_name']) . '</td></tr>'
              . '<tr><td style="padding:8px 0;color:#777;font-size:13px">Email</td>'
                  . '<td style="padding:8px 0"><a href="mailto:' . $esc($d['guest_email']) . '" style="color:#1E5C6B">'
                  . $esc($d['guest_email']) . '</a></td></tr>'
              . '<tr><td style="padding:8px 0;color:#777;font-size:13px">Room</td>'
                  . '<td style="padding:8px 0">' . $esc($d['room_name']) . ' &middot; ' . $esc($d['unit_name']) . '</td></tr>'
              . '<tr><td style="padding:8px 0;color:#777;font-size:13px">Check-in</td>'
                  . '<td style="padding:8px 0;font-weight:600">' . $esc($d['check_in']) . '</td></tr>'
              . '<tr><td style="padding:8px 0;color:#777;font-size:13px">Check-out</td>'
                  . '<td style="padding:8px 0;font-weight:600">' . $esc($d['check_out']) . '</td></tr>'
              . '<tr><td style="padding:8px 0;color:#777;font-size:13px">Expires</td>'
                  . '<td style="padding:8px 0;color:#b45309;font-weight:600">' . $esc($d['expires']) . '</td></tr>'
            . '</table>'
            . $action_block
            . '<p style="font-size:12px;color:#aaa;text-align:center;margin:24px 0 0">'
              . 'Links require admin login and ask you to confirm (and whether to email the guest) before anything happens &mdash; the hold expires automatically if not actioned.<br>'
              . '<a href="' . $esc($d['holds_url']) . '" style="color:#1E5C6B">Manage all holds &rarr;</a>'
            . '</p>'
          . '</div>'
        . '</div>'
        . '</body></html>';
}

/**
 * The property a hold belongs to — ['id', 'name', 'town']. Uses the row's
 * venue_id / venue_name when present, else looks it up. Never throws.
 */
function _mail_hold_property(array $hold): array {
    $out = ['id' => (int)($hold['venue_id'] ?? 0) ?: null, 'name' => trim((string)($hold['venue_name'] ?? '')), 'town' => ''];
    try {
        if (!$out['id'] && !empty($hold['id'])) {
            $v = db_query('SELECT r.venue_id FROM holds h JOIN units u ON u.id = h.unit_id
                             JOIN rooms r ON r.id = ' . hold_room_id_sql('h', 'u') . ' WHERE h.id = :h',
                          [':h' => (int)$hold['id']])->fetchColumn();
            $out['id'] = $v ? (int)$v : null;
        }
        if ($out['id']) {
            $row = db_query('SELECT name, location FROM venues WHERE id = :v', [':v' => $out['id']])->fetch();
            if ($row) {
                $out['name'] = $out['name'] !== '' ? $out['name'] : (string)$row['name'];
                $out['town'] = trim((string)($row['location'] ?? ''));
            }
        }
    } catch (Throwable $e) { /* copy falls back to the brand */ }
    if ($out['name'] === '') $out['name'] = 'Tribal Sand';
    return $out;
}

/** Placeholder values shared by the guest booking emails. */
function _mail_hold_vars(array $hold, array $prop): array {
    return [
        'guest_name'    => (string)($hold['guest_name'] ?? 'Guest'),
        'room_name'     => (string)($hold['room_name'] ?? ''),
        'check_in'      => (string)($hold['check_in'] ?? ''),
        'check_out'     => (string)($hold['check_out'] ?? ''),
        'property_name' => $prop['name'],
        'property_town' => $prop['town'],
    ];
}

/**
 * The guest's booking confirmation. $ctx['skip_reason'] logs it as "not sent"
 * (staff unticked "Email the guest") without sending — the rendered email is
 * still kept on the log row, so it's clear what the guest did NOT receive.
 */
function send_hold_confirmed(array $hold, array $ctx = []): array {
    require_once __DIR__ . '/booking.php';

    $site = _mail_site();
    $prop = _mail_hold_property($hold);
    $ref  = make_guest_ref((int)$hold['id']);
    $manage_url = $ref ? $site . '/booking.php?ref=' . urlencode($ref) : '';
    $vars = _mail_hold_vars($hold, $prop) + ['reference' => (string)$ref];

    $subject = email_field('hold_confirmed', 'subject', $vars, $prop['id']);
    $heading = email_field('hold_confirmed', 'heading', $vars, $prop['id']);
    $intro   = email_field('hold_confirmed', 'intro', $vars, $prop['id']);
    $footer  = email_field('hold_confirmed', 'footer_note', $vars, $prop['id']);
    $checkin_instructions = '';
    try { $checkin_instructions = setting('checkin_instructions', ''); } catch (Throwable $e) {}

    $text_lines = [
        "Dear {$hold['guest_name']},",
        '',
        _email_plain($intro),
        '',
    ];
    if ($ref) $text_lines[] = "Reference:  {$ref}";
    $text_lines[] = 'Property:   ' . $prop['name'] . ($prop['town'] !== '' ? ', ' . $prop['town'] : '');
    $text_lines[] = "Room:       {$hold['room_name']}";
    $text_lines[] = "Check-in:   {$hold['check_in']}";
    $text_lines[] = "Check-out:  {$hold['check_out']}";
    $text_lines[] = '';
    if ($checkin_instructions) {
        $text_lines[] = 'CHECK-IN INFORMATION';
        $text_lines[] = $checkin_instructions;
        $text_lines[] = '';
    }
    if ($manage_url) {
        $text_lines[] = 'View or manage your booking:';
        $text_lines[] = $manage_url;
        $text_lines[] = '';
    }
    if ($footer !== '') { $text_lines[] = _email_plain($footer); $text_lines[] = ''; }
    $text_lines[] = 'Warm regards,';
    $text_lines[] = 'Tribal Sand';
    $text_lines[] = email_guest_reply_to();

    $inner = '<p style="margin:0 0 20px;font-size:15px">Dear <strong>' . _email_esc($hold['guest_name'] ?? 'Guest') . '</strong>,</p>'
        . _email_lead_rich($intro)
        . _email_detail_block([
            ['Reference', (string)$ref],
            ['Property',  $prop['name'] . ($prop['town'] !== '' ? ' · ' . $prop['town'] : '')],
            ['Room',      $hold['room_name'] ?? ''],
            ['Check-in',  $hold['check_in'] ?? ''],
            ['Check-out', $hold['check_out'] ?? ''],
        ], 'Your booking')
        . ($manage_url ? _email_button('View your booking →', $manage_url) : '')
        . ($checkin_instructions !== ''
            ? '<div style="background:#eef6f7;border-left:3px solid #1E5C6B;padding:14px 18px;margin:20px 0;border-radius:0 4px 4px 0">'
              . '<p style="margin:0 0 6px;font-size:12px;font-weight:700;text-transform:uppercase;color:#1E5C6B;letter-spacing:.5px">Check-in Information</p>'
              . '<p style="margin:0;font-size:13px;color:#444;line-height:1.7;white-space:pre-line">' . _email_esc($checkin_instructions) . '</p></div>'
            : '')
        . _email_note($footer)
        . '<p style="font-size:14px;margin:24px 0 0">Warm regards,<br><strong>Tribal Sand</strong></p>';
    $html = _email_shell($heading, $inner, $site, $prop['name'] . ($prop['town'] !== '' ? ' — ' . $prop['town'] : ' — Kenya’s North Coast'));

    return mail_send('hold_confirmed', ['to' => (string)($hold['guest_email'] ?? ''), 'subject' => $subject, 'text' => implode("\n", $text_lines),
                                        'html' => $html, 'reply_to' => email_guest_reply_to()],
                     $ctx + ['hold_id' => (int)$hold['id'] ?: null, 'venue_id' => $prop['id']]);
}

/**
 * After staff confirm / cancel a booking: email the guest — or, when staff
 * unticked "Email the guest", log it as NOT sent (with who decided). Returns
 * the tail of the flash message ("guest emailed (x@y)." / "guest not emailed.").
 */
function hold_email_after_action(string $kind, array $row, bool $email, string $trigger): string {
    $ctx = ['trigger' => $trigger];
    if (!$email) $ctx['skip_reason'] = 'Staff unticked “Email the guest”.';
    $r = $kind === 'confirm' ? send_hold_confirmed($row, $ctx) : send_hold_cancelled($row, 'cancelled', $ctx);
    if (!$email) return 'guest not emailed.';
    return match ($r['status']) {
        'sent'       => 'guest emailed (' . $row['guest_email'] . ').',
        'suppressed' => 'guest not emailed — this email is switched off or deleted in Admin → Emails.',
        'skipped'    => 'guest not emailed — ' . lcfirst(rtrim($r['error'] ?: 'no valid address.', '.')) . '.',
        default      => 'the email to the guest FAILED (' . ($r['error'] ?: 'mail server error') . ') — see Admin → Email log.',
    };
}

function send_admin_guest_cancelled(array $hold, array $ctx = []): void {
    $site = _mail_site();
    $vars = ['guest_name' => (string)$hold['guest_name'], 'room_name' => (string)$hold['room_name'],
             'check_in' => (string)$hold['check_in'], 'check_out' => (string)$hold['check_out']];
    $intro = email_field('staff_guest_cancelled', 'intro', $vars);

    $body = implode("\n", [
        'A GUEST HAS CANCELLED THEIR OWN BOOKING',
        str_repeat('-', 40),
        "Guest:     {$hold['guest_name']}",
        "Email:     {$hold['guest_email']}",
        "Room:      {$hold['room_name']}",
        "Check-in:  {$hold['check_in']}",
        "Check-out: {$hold['check_out']}",
        '',
        _email_plain($intro),
        '',
        "View holds: {$site}/admin/holds.php",
    ]);

    $inner = _email_lead_rich($intro)
        . _email_detail_block([
            ['Guest',     $hold['guest_name']  ?? ''],
            ['Email',     $hold['guest_email'] ?? ''],
            ['Room',      $hold['room_name']   ?? ''],
            ['Check-in',  $hold['check_in']    ?? ''],
            ['Check-out', $hold['check_out']   ?? ''],
        ], 'Cancelled booking')
        . _email_button('View holds', $site . '/admin/holds.php');
    $html = _email_shell(email_field('staff_guest_cancelled', 'heading', $vars), $inner, $site);

    $guestEmail = trim((string)($hold['guest_email'] ?? ''));
    mail_send('staff_guest_cancelled', [
        'to' => email_staff_address(), 'subject' => email_field('staff_guest_cancelled', 'subject', $vars),
        'text' => $body, 'html' => $html,
        'reply_to' => filter_var($guestEmail, FILTER_VALIDATE_EMAIL) ? $guestEmail : email_staff_address(),
    ], $ctx + ['hold_id' => (int)($hold['id'] ?? 0) ?: null]);
}

/** Guest email when a hold is cancelled ($reason 'cancelled') or ran out ($reason 'expired'). */
function send_hold_cancelled(array $hold, string $reason = 'cancelled', array $ctx = []): array {
    $key   = $reason === 'expired' ? 'hold_expired' : 'hold_cancelled';
    $site  = _mail_site();
    $prop  = _mail_hold_property($hold);
    $vars  = _mail_hold_vars($hold, $prop);

    $intro  = email_field($key, 'intro', $vars, $prop['id']);
    $footer = email_field($key, 'footer_note', $vars, $prop['id']);

    $body = implode("\n", [
        "Dear {$hold['guest_name']},",
        '',
        _email_plain($intro),
        '',
        "Dates: {$hold['check_in']} to {$hold['check_out']}",
        '',
        _email_plain($footer),
        '',
        'Warm regards,',
        'Tribal Sand',
    ]);

    $inner = '<p style="margin:0 0 18px;font-size:15px">Dear <strong>' . _email_esc($hold['guest_name'] ?? 'Guest') . '</strong>,</p>'
        . _email_lead_rich($intro)
        . _email_detail_block([
            ['Villa / Room', $hold['room_name'] ?? ''],
            ['Check-in',     $hold['check_in']  ?? ''],
            ['Check-out',    $hold['check_out'] ?? ''],
        ], 'Your dates')
        . _email_note($footer, false)
        . '<p style="font-size:14px;margin:24px 0 0">Warm regards,<br><strong>Tribal Sand</strong></p>';
    $html = _email_shell(email_field($key, 'heading', $vars, $prop['id']), $inner, $site);

    return mail_send($key, ['to' => (string)($hold['guest_email'] ?? ''), 'subject' => email_field($key, 'subject', $vars, $prop['id']),
                            'text' => $body, 'html' => $html, 'reply_to' => email_guest_reply_to()],
                     $ctx + ['hold_id' => (int)($hold['id'] ?? 0) ?: null, 'venue_id' => $prop['id']]);
}

// ── Shared HTML email template (teal header + card + footer) ─────
// Reused by every notification/transactional email so guest AND staff messages
// share the Tribal Sand design system (never plain-text). Keep in step with the
// guest-acknowledgement / trip-builder templates above.

function _email_esc(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/**
 * Owner-editable text → safe HTML: escaped first, then **bold** and bare email
 * addresses / https links become markup, newlines become <br>. Nothing the
 * owner types can inject HTML. Pure.
 */
function _email_rich(string $text): string {
    $h = _email_esc($text);
    $h = preg_replace('~\*\*(.+?)\*\*~s', '<strong>$1</strong>', $h);
    $h = preg_replace_callback('~\bhttps://[^\s<]+~', fn($m) => '<a href="' . $m[0] . '" style="color:#1E5C6B">' . $m[0] . '</a>', $h);
    $h = preg_replace('~(?<![\w.@/])([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})(?![\w@])~', '<a href="mailto:$1" style="color:#1E5C6B">$1</a>', $h);
    return nl2br($h);
}

/** Editable text for a plain-text part: the **bold** markers dropped. Pure. */
function _email_plain(string $text): string {
    return str_replace('**', '', $text);
}

/** Wrap inner HTML in the branded card shell (teal header, sand accent, footer). */
function _email_shell(string $heading, string $inner, string $site = '', string $sub = 'Tribal Sand — Kenya’s North Coast'): string {
    $site = rtrim($site, '/');
    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f0f4f5;font-family:Arial,Helvetica,sans-serif">'
        . '<div style="max-width:600px;margin:32px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)">'
          . '<div style="background:#102F3A;padding:24px 32px">'
            . '<h1 style="margin:0;color:#fff;font-size:20px;font-weight:700">' . _email_esc($heading) . '</h1>'
            . '<p style="margin:6px 0 0;color:#B8965A;font-size:14px">' . _email_esc($sub) . '</p>'
          . '</div>'
          . '<div style="padding:32px">' . $inner . '</div>'
          . '<div style="background:#f9fafb;padding:16px 32px;text-align:center;font-size:12px;color:#aaa">'
            . '<a href="' . _email_esc($site ?: '#') . '" style="color:#1E5C6B;text-decoration:none">tribalsand.com</a>'
          . '</div>'
        . '</div></body></html>';
}

/** A labelled detail table (rows are [label, value]; blanks are skipped). */
function _email_detail_block(array $rows, string $title = 'Details'): string {
    $tr = '';
    foreach ($rows as [$k, $v]) {
        if ($v === '' || $v === null) continue;
        $tr .= '<tr><td style="padding:7px 0;color:#777;font-size:13px;width:140px;vertical-align:top">' . _email_esc($k) . '</td>'
             . '<td style="padding:7px 0;font-size:14px;color:#222;font-weight:600">' . _email_esc($v) . '</td></tr>';
    }
    if ($tr === '') return '';
    return '<div style="background:#f4f0e8;border-radius:6px;padding:18px 22px;margin:20px 0">'
        . '<p style="margin:0 0 10px;font-size:12px;font-weight:700;text-transform:uppercase;color:#1E5C6B;letter-spacing:.5px">' . _email_esc($title) . '</p>'
        . '<table style="width:100%;border-collapse:collapse">' . $tr . '</table></div>';
}

/** A free-text block (e.g. the guest's message), with a sand left rule. */
function _email_message_block(string $text, string $label = 'Message'): string {
    if (trim($text) === '') return '';
    return '<div style="margin:20px 0">'
        . '<p style="margin:0 0 8px;font-size:12px;font-weight:700;text-transform:uppercase;color:#777;letter-spacing:.5px">' . _email_esc($label) . '</p>'
        . '<div style="background:#f9fafb;border-left:3px solid #B8965A;padding:14px 18px;border-radius:0 4px 4px 0;font-size:14px;color:#333;line-height:1.7">'
        . nl2br(_email_esc($text)) . '</div></div>';
}

/** A primary call-to-action button (e.g. "Review in admin"). */
function _email_button(string $label, string $url): string {
    if (trim($url) === '') return '';
    return '<div style="text-align:center;margin:24px 0">'
        . '<a href="' . _email_esc($url) . '" style="display:inline-block;background:#102F3A;color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;font-size:14px">'
        . _email_esc($label) . '</a></div>';
}

/** A short lead paragraph (plain text, escaped). */
function _email_lead(string $text): string {
    return '<p style="margin:0 0 4px;font-size:15px;line-height:1.6;color:#333">' . nl2br(_email_esc($text)) . '</p>';
}

/** A lead paragraph from owner-editable text (**bold**, links). */
function _email_lead_rich(string $text): string {
    if (trim($text) === '') return '';
    return '<p style="margin:0 0 4px;font-size:15px;line-height:1.6;color:#333">' . _email_rich($text) . '</p>';
}

/** The small closing note ("If you have any questions…"). $muted = grey 13px, else body 14px. */
function _email_note(string $text, bool $muted = true): string {
    if (trim($text) === '') return '';
    return $muted
        ? '<p style="font-size:13px;color:#777;line-height:1.6;margin-top:24px">' . _email_rich($text) . '</p>'
        : '<p style="font-size:14px;color:#333;line-height:1.7;margin:18px 0 0">' . _email_rich($text) . '</p>';
}

/**
 * Hand one message to the active driver. ONLY mail_send() may call this — it
 * is what writes the send log. $meta receives ['provider', 'provider_id'].
 * The SMTP path reports the real SES result; log is always true.
 */
function _dispatch_mail(string $to, string $subject, string $body, string $from, string $reply_to, array $env, string $html = '', array &$meta = []): bool {
    $headers  = "From: {$from}\r\n";
    $headers .= "Reply-To: {$reply_to}\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "MIME-Version: 1.0\r\n";

    $id = '';
    if (!empty($env['RESEND_API_KEY'])) {
        $meta['provider'] = 'resend';
        $ok = send_resend($to, $subject, $body, $from, $reply_to, $env['RESEND_API_KEY'], $html, $id);
    } elseif (($env['MAIL_DRIVER'] ?? '') === 'smtp') {
        $meta['provider'] = 'smtp';
        $ok = send_smtp($to, $subject, $body, $headers, $env, $html, $id);
    } elseif (($env['MAIL_DRIVER'] ?? '') === 'log') {
        $meta['provider'] = 'log';
        log_mail_error("[DEV] To: {$to} | Subject: {$subject}\n{$body}");
        $ok = true;
    } else {
        $meta['provider'] = 'mail';
        $ok = @mail($to, $subject, $body, $headers);
        if (!$ok) log_mail_error("mail() failed sending '{$subject}' to {$to}");
    }
    $meta['provider_id'] = (string)$id;
    return $ok;
}

/**
 * Send a team member's reply to the enquirer. Branded with _email_shell();
 * Reply-To is the monitored reservations@ mailbox so a guest's reply reaches a
 * human. A TSR-<id>-<hash> ref is always appended to the subject — after any
 * owner wording — so api/inbound-mail.php can thread the answer back.
 *
 * Returns ['ok'=>bool, 'error'=>string] so the caller always logs the thread
 * entry and can report "saved, but email not sent" when SES can't deliver.
 */
function send_admin_reply(array $sub, string $message, array $ctx = []): array {
    $message = trim($message);
    if ($message === '') return ['ok' => false, 'error' => 'The reply is empty.'];

    $to = trim((string)($sub['guest_email'] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'The guest has no valid email address on file.'];
    }

    require_once __DIR__ . '/booking.php';   // make_submission_ref()
    // Reply-To is the apex brand address reservations@tribalsand.com — what the
    // guest sees and replies to. That mailbox lives on M365 (the apex MX), which
    // SES cannot receive, so automatic threading depends on an M365 rule that
    // FORWARDS reservations@tribalsand.com -> reservations@inbound.tribalsand.com
    // (an address SES receives). The forward preserves the subject, so the
    // [TSR-<id>] tag below is matched by api/inbound-mail.php and the reply
    // threads back. See docs/inbound-mail-setup.md.
    $reply = email_guest_reply_to();
    $site  = _mail_site();
    $guest = trim((string)($sub['guest_name'] ?? ''));
    $vars  = ['guest_name' => $guest !== '' ? $guest : 'Guest'];
    $tag   = !empty($sub['id']) ? ' [' . make_submission_ref((int)$sub['id']) . ']' : '';
    $subject  = email_field('admin_reply', 'subject', $vars) . $tag;
    $greeting = $guest !== '' ? "Dear {$guest}," : 'Hello,';

    $text = $greeting . "\n\n" . $message . "\n\n"
          . "Warm regards,\nTribal Sand\nKenya’s North Coast\n" . $reply;

    $inner = _email_lead($greeting)
           . _email_message_block($message, 'Our reply')
           . '<p style="font-size:14px;margin:22px 0 0;color:#333">Warm regards,<br><strong>Tribal Sand</strong></p>';
    $html  = _email_shell(email_field('admin_reply', 'heading', $vars), $inner, $site);

    $r = mail_send('admin_reply', ['to' => $to, 'subject' => $subject, 'text' => $text, 'html' => $html, 'reply_to' => $reply],
                   $ctx + ['submission_id' => (int)($sub['id'] ?? 0) ?: null]);
    return $r['ok']
        ? ['ok' => true, 'error' => '']
        : ['ok' => false, 'error' => 'The mail server could not send the message (check the SES / SMTP settings). The reply was still saved to the thread.'];
}

function log_mail_error(string $message): void {
    $GLOBALS['__mail_last_error'] = $message;
    $log = __DIR__ . '/../logs/mail.log';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($log, $line, FILE_APPEND | LOCK_EX);
}

/** Notify staff of a guest change request. */
function send_change_request_notification(array $hold, array $req, array $ctx = []): void {
    $site  = _mail_site();
    $admin = $site . '/admin/booking.php?hold=' . (int)$hold['id'];
    $vars  = ['guest_name' => (string)$hold['guest_name'], 'hold_id' => (string)$hold['id'], 'room_name' => (string)$hold['room_name']];
    $lines = [
        "Guest {$hold['guest_name']} ({$hold['guest_email']}) requested a change to hold #{$hold['id']} — {$hold['room_name']}.",
        '',
        'Requested:',
        '  New check-in:  ' . (($req['check_in']  ?? '') !== '' ? $req['check_in']  : '—'),
        '  New check-out: ' . (($req['check_out'] ?? '') !== '' ? $req['check_out'] : '—'),
        '  Guests:        ' . ((int)($req['guests'] ?? 0) > 0 ? (int)$req['guests'] : '—'),
        '  Note:          ' . (($req['note'] ?? '') !== '' ? $req['note'] : '—'),
        '',
        "Review in admin: {$admin}",
    ];
    $inner = _email_lead("{$hold['guest_name']} ({$hold['guest_email']}) requested a change to hold #{$hold['id']} — {$hold['room_name']}.")
        . _email_detail_block([
            ['New check-in',  ($req['check_in']  ?? '') !== '' ? $req['check_in']  : '—'],
            ['New check-out', ($req['check_out'] ?? '') !== '' ? $req['check_out'] : '—'],
            ['Guests',        (int)($req['guests'] ?? 0) > 0 ? (int)$req['guests'] : '—'],
            ['Note',          ($req['note'] ?? '') !== '' ? $req['note'] : '—'],
        ], 'Requested change')
        . _email_button('Review in admin', $admin);
    mail_send('staff_change_request', [
        'to' => email_staff_address(), 'subject' => email_field('staff_change_request', 'subject', $vars),
        'text' => implode("\n", $lines), 'html' => _email_shell(email_field('staff_change_request', 'heading', $vars), $inner, $site),
        'reply_to' => filter_var((string)$hold['guest_email'], FILTER_VALIDATE_EMAIL) ? (string)$hold['guest_email'] : email_staff_address(),
    ], $ctx + ['hold_id' => (int)$hold['id'] ?: null]);
}

/** Notify staff of a guest add-on request. */
function send_addon_request_notification(array $hold, array $addon, array $ctx = []): void {
    $site  = _mail_site();
    $admin = $site . '/admin/booking.php?hold=' . (int)$hold['id'];
    $vars  = ['guest_name' => (string)$hold['guest_name'], 'hold_id' => (string)$hold['id'], 'room_name' => (string)$hold['room_name'],
              'addon' => (string)($addon['kind'] ?? '')];
    $lines = [
        "Guest {$hold['guest_name']} ({$hold['guest_email']}) added a {$addon['kind']} to hold #{$hold['id']} — {$hold['room_name']}.",
        '',
        'Details: ' . (($addon['details'] ?? '') !== '' ? $addon['details'] : '—'),
        '',
        "Review in admin: {$admin}",
    ];
    $inner = _email_lead("{$hold['guest_name']} ({$hold['guest_email']}) added a {$addon['kind']} to hold #{$hold['id']} — {$hold['room_name']}.")
        . _email_detail_block([
            ['Add-on',  $addon['kind'] ?? ''],
            ['Details', ($addon['details'] ?? '') !== '' ? $addon['details'] : '—'],
        ], 'Add-on request')
        . _email_button('Review in admin', $admin);
    mail_send('staff_addon_request', [
        'to' => email_staff_address(), 'subject' => email_field('staff_addon_request', 'subject', $vars),
        'text' => implode("\n", $lines), 'html' => _email_shell(email_field('staff_addon_request', 'heading', $vars), $inner, $site),
        'reply_to' => filter_var((string)$hold['guest_email'], FILTER_VALIDATE_EMAIL) ? (string)$hold['guest_email'] : email_staff_address(),
    ], $ctx + ['hold_id' => (int)$hold['id'] ?: null]);
}

// ── Restaurant reservation emails ───────────────────────────────
// Request model: guest submits → pending. send_reservation_received() sends the
// guest acknowledgement AND the staff alert. send_reservation_confirmed() goes to
// the guest when staff approve. $res is a reservations row joined with venue_name.

/** Friendly "Fri, 22 Aug 2026 · 7:30 PM" label for a reservation's date + time. */
function _reservation_when(array $res): string {
    $d = strtotime((string)($res['reservation_date'] ?? ''));
    $t = strtotime((string)($res['reservation_time'] ?? ''));
    $parts = [];
    if ($d) $parts[] = date('D, j M Y', $d);
    if ($t) $parts[] = date('g:i A', $t);
    return implode(' · ', $parts);
}

/** Shared [label,value] detail rows for a reservation. */
function _reservation_rows(array $res): array {
    $party = max(1, (int)($res['party_size'] ?? 1));
    return [
        ['Property',  $res['venue_name'] ?? ''],
        ['Date',      ($d = strtotime((string)($res['reservation_date'] ?? ''))) ? date('D, j M Y', $d) : ''],
        ['Time',      ($t = strtotime((string)($res['reservation_time'] ?? ''))) ? date('g:i A', $t) : ''],
        ['Party',     $party . ' ' . ($party === 1 ? 'guest' : 'guests')],
        ['Name',      $res['guest_name']  ?? ''],
        ['Reference', $res['reference']   ?? ''],
    ];
}

function _reservation_vars(array $res): array {
    return [
        'guest_name'    => trim((string)($res['guest_name'] ?? '')) ?: 'Guest',
        'property_name' => (string)($res['venue_name'] ?? 'Tribal Sand'),
        'when'          => _reservation_when($res),
        'reference'     => (string)($res['reference'] ?? ''),
    ];
}

/** Guest acknowledgement ("request received, pending confirmation") + staff alert. */
function send_reservation_received(array $res, array $ctx = []): void {
    $site  = _mail_site();
    $vars  = _reservation_vars($res);
    $rows  = _reservation_rows($res);
    $vid   = (int)($res['venue_id'] ?? 0) ?: null;
    $ctx  += ['reservation_id' => (int)($res['id'] ?? 0) ?: null, 'venue_id' => $vid];

    // ── Guest acknowledgement ──
    $guestEmail = trim((string)($res['guest_email'] ?? ''));
    if ($guestEmail !== '') {
        $key    = 'reservation_received_guest';
        $intro  = email_field($key, 'intro', $vars, $vid);
        $footer = email_field($key, 'footer_note', $vars, $vid);
        $textLines = ["Dear {$vars['guest_name']},", '', _email_plain($intro), '', 'YOUR REQUEST'];
        foreach ($rows as [$k, $v]) if ($v !== '') $textLines[] = "  {$k}: {$v}";
        $textLines[] = '';
        if (($res['notes'] ?? '') !== '') { $textLines[] = 'Your note:'; $textLines[] = (string)$res['notes']; $textLines[] = ''; }
        $textLines[] = _email_plain($footer);
        $textLines[] = '';
        $textLines[] = 'Warm regards,';
        $textLines[] = 'Tribal Sand';

        $inner = '<p style="margin:0 0 18px;font-size:15px">Dear <strong>' . _email_esc($vars['guest_name']) . '</strong>,</p>'
            . _email_lead_rich($intro)
            . _email_detail_block($rows, 'Your request')
            . _email_message_block((string)($res['notes'] ?? ''), 'Your note')
            . _email_note($footer)
            . '<p style="font-size:14px;margin:24px 0 0">Warm regards,<br><strong>Tribal Sand</strong></p>';
        mail_send($key, ['to' => $guestEmail, 'subject' => email_field($key, 'subject', $vars, $vid), 'text' => implode("\n", $textLines),
                         'html' => _email_shell(email_field($key, 'heading', $vars, $vid), $inner, $site), 'reply_to' => email_guest_reply_to()], $ctx);
    }

    // ── Staff alert ──
    $key      = 'reservation_received_staff';
    $adminUrl = $site . '/admin/reservations.php';
    $intro    = email_field($key, 'intro', $vars, $vid);
    $staffRows = array_merge($rows, [
        ['Phone', $res['guest_phone'] ?? ''],
        ['Email', $res['guest_email'] ?? ''],
    ]);
    $textS = implode("\n", array_merge(
        ['NEW TABLE RESERVATION REQUEST', str_repeat('-', 40)],
        array_map(fn($r) => $r[1] !== '' ? "{$r[0]}: {$r[1]}" : '', $staffRows),
        ['', 'Note: ' . (($res['notes'] ?? '') !== '' ? $res['notes'] : '—'), '', "Review: {$adminUrl}"]
    ));
    $innerS = _email_lead_rich($intro)
        . _email_detail_block($staffRows, 'Reservation')
        . _email_message_block((string)($res['notes'] ?? ''), 'Guest note')
        . _email_button('Review reservations', $adminUrl);
    mail_send($key, ['to' => email_staff_address(), 'subject' => email_field($key, 'subject', $vars, $vid), 'text' => $textS,
                     'html' => _email_shell(email_field($key, 'heading', $vars, $vid), $innerS, $site),
                     'reply_to' => filter_var($guestEmail, FILTER_VALIDATE_EMAIL) ? $guestEmail : email_staff_address()], $ctx);
}

/** Guest confirmation email when staff approve a reservation. */
function send_reservation_confirmed(array $res, array $ctx = []): void {
    $to = trim((string)($res['guest_email'] ?? ''));
    if ($to === '') return;   // no guest email → nothing to send (phone-only booking)

    $key    = 'reservation_confirmed';
    $site   = _mail_site();
    $vars   = _reservation_vars($res);
    $vid    = (int)($res['venue_id'] ?? 0) ?: null;
    $rows   = _reservation_rows($res);
    $intro  = email_field($key, 'intro', $vars, $vid);
    $footer = email_field($key, 'footer_note', $vars, $vid);

    $textLines = ["Dear {$vars['guest_name']},", '', _email_plain($intro), '', 'YOUR RESERVATION'];
    foreach ($rows as [$k, $v]) if ($v !== '') $textLines[] = "  {$k}: {$v}";
    $textLines[] = '';
    $textLines[] = _email_plain($footer);
    $textLines[] = '';
    $textLines[] = 'Warm regards,';
    $textLines[] = 'Tribal Sand';

    $inner = '<p style="margin:0 0 18px;font-size:15px">Dear <strong>' . _email_esc($vars['guest_name']) . '</strong>,</p>'
        . _email_lead_rich($intro)
        . _email_detail_block($rows, 'Your reservation')
        . _email_note($footer)
        . '<p style="font-size:14px;margin:24px 0 0">Warm regards,<br><strong>Tribal Sand</strong></p>';

    mail_send($key, ['to' => $to, 'subject' => email_field($key, 'subject', $vars, $vid), 'text' => implode("\n", $textLines),
                     'html' => _email_shell(email_field($key, 'heading', $vars, $vid), $inner, $site), 'reply_to' => email_guest_reply_to()],
              $ctx + ['reservation_id' => (int)($res['id'] ?? 0) ?: null, 'venue_id' => $vid]);
}

/**
 * Email a till receipt (a pos_fetch_sale() row) to $to. Returns whether it was handed
 * to the mailer. Branded like the other guest emails; Reply-To is reservations@.
 */
function send_pos_receipt(array $s, string $to, array $ctx = []): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $key   = 'pos_receipt';
    $site  = _mail_site();
    $cur   = (string)$s['currency'];
    $m     = fn($v) => $cur . ' ' . number_format((float)$v, 2);
    $outlet = (string)($s['outlet_name'] ?? 'Tribal Sand');
    $when  = date('j M Y, H:i', strtotime((string)$s['created_at']));
    $docs  = function_exists('pos_sale_document_numbers') && !empty($s['id']) ? pos_sale_document_numbers((int)$s['id']) : [];
    $labels = defined('POS_PAYMENT_METHODS') ? POS_PAYMENT_METHODS : [];
    $incl   = in_array($s['vat_inclusive'] ?? true, [true, 't', 1, '1', 'true'], true);   // Postgres 't' or PHP true
    $vars   = ['outlet_name' => $outlet, 'reference' => (string)$s['reference']];

    $rows = [];
    foreach ($s['lines'] ?? [] as $l) $rows[] = [(int)$l['qty'] . ' × ' . $l['name'], $m($l['line_total'])];
    if ((float)$s['service_charge'] > 0) $rows[] = ['Service charge', $m($s['service_charge'])];
    if ((float)($s['vat_amount'] ?? 0) > 0 && !$incl) $rows[] = ['VAT (' . rtrim(rtrim((string)$s['vat_pct'], '0'), '.') . '%)', $m($s['vat_amount'])];
    if ((float)($s['tip_amount'] ?? 0) > 0) $rows[] = ['Tip', $m($s['tip_amount'])];
    $rows[] = ['Total · ' . ($labels[$s['payment_method']] ?? $s['payment_method']), $m($s['total'])];
    if ((float)($s['vat_amount'] ?? 0) > 0 && $incl) $rows[] = ['Includes VAT ' . rtrim(rtrim((string)$s['vat_pct'], '0'), '.') . '%', $m($s['vat_amount'])];
    if ($s['payment_method'] === 'room_charge' && !empty($s['bill_amount'])) $rows[] = ['Charged to your room bill', ($s['bill_currency'] ?? '') . ' ' . number_format((float)$s['bill_amount'], 2)];

    $intro  = email_field($key, 'intro', $vars);
    $footer = email_field($key, 'footer_note', $vars);
    $text = _email_plain($intro) . "\n\nReceipt {$s['reference']} · {$when}\n";
    if ($docs) $text .= 'Tax invoice: ' . implode(', ', $docs) . "\n";
    $text .= "\n";
    foreach ($rows as [$k, $v]) $text .= "  {$k}: {$v}\n";
    $text .= "\n" . _email_plain($footer) . "\n\nTribal Sand";

    $meta = [['Receipt', (string)$s['reference']], ['Date', $when], ['Served at', $outlet]];
    if ($docs) $meta[] = ['Tax invoice', implode(', ', $docs)];
    $inner = _email_lead_rich($intro)
        . _email_detail_block($meta, 'Receipt')
        . _email_detail_block($rows, 'What you bought')
        . _email_note($footer);
    $r = mail_send($key, ['to' => $to, 'subject' => email_field($key, 'subject', $vars), 'text' => $text,
                          'html' => _email_shell(email_field($key, 'heading', $vars), $inner, $site), 'reply_to' => email_guest_reply_to()],
                   $ctx + ['pos_sale_id' => (int)($s['id'] ?? 0) ?: null]);
    return $r['ok'];
}

/**
 * Front-desk notice on check-in completion. Before the Email Notifications
 * Center this only worked through Resend, so production (SES) never sent it —
 * it now goes through mail_send() like everything else, and ships switched OFF
 * (email_registry(): default_on false) so turning it on is the owner's call.
 */
function send_checkin_completed(array $hold, ?array $data, array $ctx = []): void {
    $vars = ['guest_name' => (string)($hold['guest_name'] ?? ''), 'room_name' => (string)($hold['room_name'] ?? '')];
    $lines = [
        'Guest: ' . ($hold['guest_name'] ?? ''),
        'Room: '  . ($hold['room_name'] ?? ''),
        'Flight: ' . trim((string)($data['flight_number'] ?? '') . ' ' . (string)($data['arrival_airport'] ?? '')),
        'Arrival: ' . (string)($data['arrival_at'] ?? ''),
        'Transfer: ' . (($data['needs_transfer'] ?? null) ? ('yes — ' . (string)($data['transfer_details'] ?? '')) : 'no'),
        'Dietary: ' . (string)($data['dietary'] ?? ''),
        'Requests: ' . (string)($data['special_requests'] ?? ''),
    ];
    $adminUrl = site_url('/admin/booking.php?hold=' . (int)($hold['id'] ?? 0) . '&tab=checkin');
    $intro = email_field('staff_checkin_completed', 'intro', $vars);
    $body = _email_plain($intro) . "\n\n" . implode("\n", $lines) . "\n\n" . $adminUrl;

    $inner = _email_lead_rich($intro)
        . _email_detail_block([
            ['Guest',    $hold['guest_name'] ?? ''],
            ['Room',     $hold['room_name']  ?? ''],
            ['Flight',   trim((string)($data['flight_number'] ?? '') . ' ' . (string)($data['arrival_airport'] ?? ''))],
            ['Arrival',  (string)($data['arrival_at'] ?? '')],
            ['Transfer', ($data['needs_transfer'] ?? null) ? ('Yes — ' . (string)($data['transfer_details'] ?? '')) : 'No'],
            ['Dietary',  (string)($data['dietary'] ?? '')],
            ['Requests', (string)($data['special_requests'] ?? '')],
        ], 'Check-in details')
        . _email_button('Open in admin', $adminUrl);

    mail_send('staff_checkin_completed', [
        'to' => email_staff_address(), 'subject' => email_field('staff_checkin_completed', 'subject', $vars), 'text' => $body,
        'html' => _email_shell(email_field('staff_checkin_completed', 'heading', $vars), $inner, _mail_site()),
    ], $ctx + ['hold_id' => (int)($hold['id'] ?? 0) ?: null]);
}

/** Admin password-reset link. Always sent (locked), never re-routed. */
function send_password_reset(string $email, string $resetUrl, array $ctx = []): array {
    $text = "You requested a password reset for the Tribal Sand admin panel.\n\n"
          . "Click the link below to set a new password. This link expires in 1 hour.\n\n"
          . $resetUrl . "\n\n"
          . "If you did not request this, you can safely ignore this email.\n\n"
          . "— Tribal Sand";
    $inner = _email_lead('You requested a password reset for the Tribal Sand admin panel. The link below expires in 1 hour.')
        . _email_button('Set a new password', $resetUrl)
        . _email_note('If you did not request this, you can safely ignore this email.');
    $env = parse_env();
    return mail_send('password_reset', [
        'to' => $email, 'subject' => email_field('password_reset', 'subject'), 'text' => $text,
        'html' => _email_shell(email_field('password_reset', 'heading'), $inner, _mail_site()),
        'reply_to' => (string)($env['MAIL_FROM'] ?? 'noreply@tribalsand.com'),
    ], $ctx + ['triggered_by' => 'guest', 'trigger' => 'forgot_password', 'redact' => [$resetUrl]]);   // never keep a live reset link in the log
}
