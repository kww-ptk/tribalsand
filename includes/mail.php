<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function send_notification(array $sub): void {
    $env        = parse_env();
    $to         = setting('notify_email', 'reservations@tribalsand.com');
    $from       = $env['MAIL_FROM'] ?? 'noreply@tribalsand.com';
    $driver     = $env['MAIL_DRIVER'] ?? 'mail';
    $site_url   = rtrim($env['SITE_URL'] ?? '', '/');

    $type       = ucfirst($sub['type'] ?? 'enquiry');
    $guest      = $sub['guest_name']  ?? 'Guest';
    $room_name  = $sub['room_name']   ?? '';
    $date       = date('d M Y', strtotime($sub['created_at'] ?? 'now'));

    $subject = $room_name
        ? "[{$type}] {$room_name} — {$guest} — {$date}"
        : "[{$type}] {$guest} — {$date}";

    $body = build_email_body($sub, $site_url);          // plain-text fallback part
    $html = _notify_html($sub, $site_url);              // designed HTML part

    $headers  = "From: {$from}\r\n";
    $headers .= "Reply-To: {$sub['guest_email']}\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "MIME-Version: 1.0\r\n";

    if (!empty($env['RESEND_API_KEY'])) {
        send_resend($to, $subject, $body, $from, $sub['guest_email'] ?? '', $env['RESEND_API_KEY'], $html);
    } elseif ($driver === 'smtp') {
        send_smtp($to, $subject, $body, $headers, $env, $html);
    } elseif ($driver === 'log') {
        log_mail_error("[DEV] To: {$to} | Subject: {$subject}\n{$body}");
    } else {
        if (!@mail($to, $subject, $body, $headers)) {
            log_mail_error("mail() failed for submission #{$sub['id']}");
        }
    }
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
function _notify_html(array $sub, string $site): string {
    $type = ucfirst($sub['type'] ?? 'enquiry');
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
    $inner = _email_lead('A new ' . strtolower($type) . ' has come in through the website.')
        . _email_detail_block($rows, $type . ' details')
        . _email_message_block((string)($sub['message'] ?? ''), 'Message')
        . _email_detail_block($track, 'Tracking')
        . $btn;
    return _email_shell('New ' . $type . ' enquiry', $inner, $site);
}

function send_resend(string $to, string $subject, string $text, string $from, string $reply_to, string $api_key, string $html = ''): void {
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
    }
}

/**
 * Send via SMTP — implemented for Amazon SES (also works with any SMTP relay).
 * STARTTLS on 587 (SMTP_SECURITY=tls, default) or implicit TLS on 465 (=ssl),
 * AUTH LOGIN with SES SMTP credentials. Builds a multipart/alternative message
 * so the branded HTML part (when present) and the plain-text fallback both go
 * out. Any failure logs and, as a last resort, falls back to PHP mail().
 *
 * Env: SMTP_HOST (or derived email-smtp.<S3_REGION>.amazonaws.com), SMTP_PORT,
 *      SMTP_USER, SMTP_PASS, SMTP_SECURITY (tls|ssl), SMTP_EHLO, MAIL_FROM.
 */
function send_smtp(string $to, string $subject, string $body, string $headers, array $env, string $html = ''): void {
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
        if (!@mail($to, $subject, $body, $headers)) log_mail_error("mail() fallback failed for: {$to}");
        return;
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
    if (!$fp) { log_mail_error("SMTP connect failed to {$host}:{$port} — {$errno} {$errstr}"); return; }
    stream_set_timeout($fp, 20);

    $read = function () use ($fp): int {
        $line = '';
        do { $line = fgets($fp, 515); if ($line === false) return 0; }
        while (strlen($line) >= 4 && $line[3] === '-');   // consume multiline replies
        return (int)substr($line, 0, 3);
    };
    $send = function (string $c) use ($fp): void { fwrite($fp, $c . "\r\n"); };
    $fail = function (string $why) use ($fp): void { log_mail_error($why); @fwrite($fp, "QUIT\r\n"); fclose($fp); };

    if ($read() !== 220) { $fail('SMTP: no 220 greeting'); return; }
    $ehlo = 'EHLO ' . (string)($env['SMTP_EHLO'] ?? $dom);
    $send($ehlo); if ($read() !== 250) { $fail('SMTP: EHLO rejected'); return; }

    if ($secure === 'tls') {
        $send('STARTTLS'); if ($read() !== 220) { $fail('SMTP: STARTTLS rejected'); return; }
        $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (!@stream_socket_enable_crypto($fp, true, $crypto)) { $fail('SMTP: TLS handshake failed'); return; }
        $send($ehlo); if ($read() !== 250) { $fail('SMTP: EHLO after STARTTLS rejected'); return; }
    }

    $send('AUTH LOGIN');        if ($read() !== 334) { $fail('SMTP: AUTH LOGIN rejected'); return; }
    $send(base64_encode($user)); if ($read() !== 334) { $fail('SMTP: username rejected'); return; }
    $send(base64_encode($pass)); if ($read() !== 235) { $fail('SMTP: authentication failed'); return; }

    $send("MAIL FROM:<{$fromAddr}>"); if ($read() !== 250) { $fail('SMTP: MAIL FROM rejected'); return; }
    $send("RCPT TO:<{$toAddr}>");     if (!in_array($read(), [250, 251], true)) { $fail('SMTP: RCPT TO rejected'); return; }
    $send('DATA');                    if ($read() !== 354) { $fail('SMTP: DATA rejected'); return; }

    fwrite($fp, $data . "\r\n.\r\n");
    if ($read() !== 250) { $fail('SMTP: message not accepted'); return; }

    $send('QUIT');
    fclose($fp);
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
//     room_name / tour_name / check_in / check_out / agency_name / subject / message.
function send_guest_acknowledgement(array $a): void {
    $to = trim((string)($a['guest_email'] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return;

    $env   = parse_env();
    $from  = $env['MAIL_FROM'] ?? 'Tribal Sand <noreply@tribalsand.com>';
    $reply = setting('notify_email', 'reservations@tribalsand.com');
    $site  = rtrim($env['SITE_URL'] ?? $env['APP_URL'] ?? 'https://tribalsand.com', '/');
    $name  = trim((string)($a['guest_name'] ?? '')) ?: 'Guest';

    [$subject, $intro] = match ($a['kind'] ?? 'enquiry') {
        'hold'    => ['We’ve received your booking request — Tribal Sand',
                      'Thank you for your booking request. We’re holding your selected dates for 24 hours while our team confirms availability — you’ll receive a separate confirmation email shortly.'],
        'contact' => ['We’ve received your message — Tribal Sand',
                      'Thank you for getting in touch. We’ve received your message and a member of our team will reply as soon as possible.'],
        'agency'  => ['We’ve received your enquiry — Tribal Sand',
                      'Thank you for your interest in working with us. We’ve received your travel agency enquiry and our team will be in touch shortly.'],
        default   => ['We’ve received your enquiry — Tribal Sand',
                      'Thank you for your enquiry. We’ve received your message and a member of our reservations team will get back to you within 24 hours.'],
    };

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
              'guests' => 'Guests', 'agency_name' => 'Agency', 'subject' => 'Subject'] as $key => $label) {
        if (empty($a[$key])) continue;
        $val = (string)$a[$key];
        if (($key === 'check_in' || $key === 'check_out') && ($ts = strtotime($val))) {
            $val = date('D, j M Y', $ts);   // e.g. "Fri, 22 Aug 2026"
        }
        $rows[] = [$label, $val];
    }

    $manage_url  = '';
    $access_code = trim((string)($a['access_code'] ?? ''));
    if (($a['kind'] ?? '') === 'hold' && !empty($a['hold_id'])) {
        require_once __DIR__ . '/booking.php';
        $manage_url = make_manage_url((int)$a['hold_id']);
    }

    $tl = ["Dear {$name},", '', $intro, ''];
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
    $tl[] = "If your enquiry is urgent you can reply to this email or write to {$reply}.";
    $tl[] = '';
    $tl[] = 'Warm regards,';
    $tl[] = 'Tribal Sand';
    $tl[] = 'Kenya’s North Coast';
    if ($site) $tl[] = $site;
    $text = implode("\n", $tl);

    $html = _guest_ack_html([
        'name'        => $name,
        'intro'       => $intro,
        'rows'        => $rows,
        'message'     => (string)($a['message'] ?? ''),
        'reply'       => $reply,
        'site'        => $site,
        'manage_url'  => $manage_url,
        'access_code' => $access_code,
    ]);

    _dispatch_mail($to, $subject, $text, $from, $reply, $env, $html);
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
            . '<h1 style="margin:0;color:#fff;font-size:20px;font-weight:700">Thank you for contacting us</h1>'
            . '<p style="margin:6px 0 0;color:#B8965A;font-size:14px">Tribal Sand &mdash; Kenya’s North Coast</p>'
          . '</div>'
          . '<div style="padding:32px">'
            . '<p style="margin:0 0 18px;font-size:15px">Dear <strong>' . $esc($d['name']) . '</strong>,</p>'
            . '<p style="margin:0 0 4px;font-size:15px;line-height:1.6">' . $esc($d['intro']) . '</p>'
            . $detail_block
            . $message_block
            . $manage
            . '<p style="font-size:13px;color:#777;line-height:1.6;margin-top:24px">If your enquiry is urgent you can simply reply to this email, or write to '
              . '<a href="mailto:' . $esc($d['reply']) . '" style="color:#1E5C6B">' . $esc($d['reply']) . '</a>.</p>'
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

function send_trip_builder_emails(array $d, int $id): void {
    $env   = parse_env();
    $from  = $env['MAIL_FROM'] ?? 'Tribal Sand <noreply@tribalsand.com>';
    $reply = setting('notify_email', 'reservations@tribalsand.com');
    $site  = rtrim($env['SITE_URL'] ?? $env['APP_URL'] ?? 'https://tribalsand.com', '/');

    $g     = $d['guest'] ?? [];
    $email = trim((string)($g['email'] ?? ''));
    $name  = trim(((string)($g['firstName'] ?? '')) . ' ' . ((string)($g['lastName'] ?? '')));
    $ref   = 'TSB-' . $id;

    // Guest acknowledgement
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $subject = 'Your Kenya coast trip plan — Tribal Sand (' . $ref . ')';
        _dispatch_mail(
            $email, $subject,
            _trip_builder_text($d, 'guest', $ref, $site),
            $from, $reply, $env,
            _trip_builder_html($d, 'guest', $ref, $site)
        );
    }

    // Staff notification
    $subjectS = '[Trip Builder] ' . ($name !== '' ? $name : 'Guest') . ' — ' . _tb_prop_name((string)($d['trip']['prop'] ?? '')) . ' — ' . $ref;
    _dispatch_mail(
        $reply, $subjectS,
        _trip_builder_text($d, 'staff', $ref, $site, $id),
        $from, $email !== '' ? $email : $reply, $env,
        _trip_builder_html($d, 'staff', $ref, $site, $id)
    );
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
        $lead = '<p style="margin:0 0 14px;font-size:15px;font-family:Arial,Helvetica,sans-serif">Dear <strong>' . $esc($first) . '</strong>,</p>'
              . '<p style="margin:0 0 8px;font-size:15px;color:#6B6050;line-height:1.75;font-family:Arial,Helvetica,sans-serif">Thank you for planning your Kenya coast escape with us. Our concierge team will personally review everything below and reply within <strong style="color:#141412">24 hours</strong> with a tailored quote. <strong>No payment is taken at this stage.</strong></p>';
        $extra = '';
        $footerLink = '';
    }

    $refBar = '<table width="100%" cellpadding="0" cellspacing="0" style="background:#F2E8D6"><tr>'
            . '<td style="padding:12px 32px;font-size:11px;letter-spacing:.22em;color:#B8965A;text-transform:uppercase;font-family:Arial,Helvetica,sans-serif">Reference</td>'
            . '<td align="right" style="padding:12px 32px;font-size:14px;color:#141412;font-family:Arial,Helvetica,sans-serif;font-weight:700">' . $esc($ref) . '</td>'
            . '</tr></table>';

    $contactLine = $audience === 'guest'
        ? ' or write to <a href="mailto:' . $esc(setting('notify_email', 'reservations@tribalsand.com')) . '" style="color:#1E5C6B">' . $esc(setting('notify_email', 'reservations@tribalsand.com')) . '</a>'
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
        $L[] = 'Dear ' . (trim((string)($g['firstName'] ?? '')) ?: 'Guest') . ',';
        $L[] = '';
        $L[] = 'Thank you for planning your trip with Tribal Sand. Our concierge team will reply within 24 hours with a tailored quote. No payment is taken at this stage.';
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

function send_hold_notification(array $hold): void {
    require_once __DIR__ . '/booking.php';

    $env     = parse_env();
    $to      = setting('notify_email', 'reservations@tribalsand.com');
    $from    = $env['MAIL_FROM'] ?? 'noreply@tribalsand.com';
    $site    = rtrim($env['SITE_URL'] ?? '', '/');
    $holdId  = (int)$hold['id'];
    $expires = isset($hold['expires_at']) ? date('d M Y H:i', strtotime($hold['expires_at'])) . ' (UTC+3)' : '24 hours';

    $subject = "[Hold Request] {$hold['room_name']} — {$hold['guest_name']} — {$hold['check_in']} to {$hold['check_out']}";

    // Build action URLs if token secret is configured
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
    $text = implode("\n", $text_lines);

    // HTML email with action buttons
    $html = _hold_notification_html([
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

    _dispatch_mail($to, $subject, $text, $from, $hold['guest_email'] ?? '', $env, $html);
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
            . '<h1 style="margin:0;color:#fff;font-size:20px;font-weight:700">New Hold Request</h1>'
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
              . 'Links require admin login &mdash; hold expires automatically if not actioned.<br>'
              . '<a href="' . $esc($d['holds_url']) . '" style="color:#1E5C6B">Manage all holds &rarr;</a>'
            . '</p>'
          . '</div>'
        . '</div>'
        . '</body></html>';
}

function send_hold_confirmed(array $hold): void {
    require_once __DIR__ . '/booking.php';

    $env  = parse_env();
    $from = $env['MAIL_FROM'] ?? 'noreply@tribalsand.com';
    $site = rtrim($env['SITE_URL'] ?? '', '/');

    $ref        = make_guest_ref((int)$hold['id']);
    $manage_url = $ref ? $site . '/booking.php?ref=' . urlencode($ref) : '';

    $subject = "Booking Confirmed — {$hold['room_name']} — {$hold['check_in']} to {$hold['check_out']}";

    $checkin_instructions = setting('checkin_instructions', '');

    $text_lines = [
        "Dear {$hold['guest_name']},",
        '',
        'We are delighted to confirm your booking at Tribal Sand, Watamu.',
        '',
        "Reference:  {$ref}",
        "Room:       {$hold['room_name']}",
        "Check-in:   {$hold['check_in']}",
        "Check-out:  {$hold['check_out']}",
        '',
    ];
    if ($checkin_instructions) {
        $text_lines[] = 'CHECK-IN INFORMATION';
        $text_lines[] = $checkin_instructions;
        $text_lines[] = '';
    }
    $text_lines[] = 'Our team will be in touch if you have any further questions.';
    $text_lines[] = '';
    if ($manage_url) {
        $text_lines[] = 'View or manage your booking:';
        $text_lines[] = $manage_url;
        $text_lines[] = '';
    }
    $text_lines[] = 'Warm regards,';
    $text_lines[] = 'Tribal Sand';
    $text_lines[] = 'reservations@tribalsand.com';

    $body = implode("\n", $text_lines);
    $html = _hold_confirmed_html([
        'guest_name'           => $hold['guest_name'],
        'room_name'            => $hold['room_name'],
        'unit_name'            => $hold['unit_name'] ?? '',
        'check_in'             => $hold['check_in'],
        'check_out'            => $hold['check_out'],
        'ref'                  => $ref,
        'manage_url'           => $manage_url,
        'site'                 => $site,
        'checkin_instructions' => $checkin_instructions,
    ]);

    _dispatch_mail($hold['guest_email'], $subject, $body, $from, $from, $env, $html);
}

function _hold_confirmed_html(array $d): string {
    $esc  = fn(string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $site = rtrim($d['site'] ?? '', '/');

    $manage_block = '';
    if ($d['manage_url']) {
        $manage_block =
            '<div style="margin:28px 0;text-align:center">'
            . '<a href="' . $esc($d['manage_url']) . '" style="background:#1E5C6B;color:#fff;padding:14px 28px;'
            . 'border-radius:6px;text-decoration:none;font-size:15px;font-weight:700;display:inline-block">'
            . 'View Your Booking &rarr;</a>'
            . '</div>';
    }

    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f0f4f5;font-family:Arial,Helvetica,sans-serif">'
        . '<div style="max-width:600px;margin:32px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)">'
          . '<div style="background:#1E5C6B;padding:24px 32px">'
            . '<h1 style="margin:0;color:#fff;font-size:20px;font-weight:700">Booking Confirmed</h1>'
            . '<p style="margin:6px 0 0;color:#bcdfe6;font-size:14px">Tribal Sand — Kenya</p>'
          . '</div>'
          . '<div style="padding:32px">'
            . '<p style="margin:0 0 20px;font-size:15px">Dear <strong>' . $esc($d['guest_name']) . '</strong>,</p>'
            . '<p style="margin:0 0 20px;font-size:15px;line-height:1.6">We are delighted to confirm your booking. Everything is set — we look forward to welcoming you!</p>'
            . '<div style="background:#eef6f7;border-radius:6px;padding:20px 24px;margin-bottom:8px">'
              . '<table style="width:100%;border-collapse:collapse">'
                . ($d['ref'] ? '<tr><td style="padding:7px 0;color:#777;font-size:13px;width:100px">Reference</td>'
                    . '<td style="padding:7px 0;font-weight:700;font-family:monospace;font-size:14px;color:#1E5C6B">' . $esc($d['ref']) . '</td></tr>' : '')
                . '<tr><td style="padding:7px 0;color:#777;font-size:13px">Room</td>'
                    . '<td style="padding:7px 0;font-weight:600">' . $esc($d['room_name']) . '</td></tr>'
                . '<tr><td style="padding:7px 0;color:#777;font-size:13px">Check-in</td>'
                    . '<td style="padding:7px 0;font-weight:600">' . $esc($d['check_in']) . '</td></tr>'
                . '<tr><td style="padding:7px 0;color:#777;font-size:13px">Check-out</td>'
                    . '<td style="padding:7px 0;font-weight:600">' . $esc($d['check_out']) . '</td></tr>'
              . '</table>'
            . '</div>'
            . $manage_block
            . (!empty($d['checkin_instructions'])
                ? '<div style="background:#eef6f7;border-left:3px solid #1E5C6B;padding:14px 18px;margin:20px 0;border-radius:0 4px 4px 0">'
                  . '<p style="margin:0 0 6px;font-size:12px;font-weight:700;text-transform:uppercase;color:#1E5C6B;letter-spacing:.5px">Check-in Information</p>'
                  . '<p style="margin:0;font-size:13px;color:#444;line-height:1.7;white-space:pre-line">'
                  . htmlspecialchars($d['checkin_instructions'], ENT_QUOTES, 'UTF-8')
                  . '</p></div>'
                : '')
            . '<p style="font-size:13px;color:#777;line-height:1.6;margin-top:24px">If you have any questions, please email us at <a href="mailto:reservations@tribalsand.com" style="color:#1E5C6B">reservations@tribalsand.com</a>.</p>'
            . '<p style="font-size:14px;margin:24px 0 0">Warm regards,<br><strong>Tribal Sand</strong></p>'
          . '</div>'
          . '<div style="background:#f9fafb;padding:16px 32px;text-align:center;font-size:12px;color:#aaa">'
            . '<a href="' . $esc($site) . '" style="color:#1E5C6B">tribalsand.com</a>'
          . '</div>'
        . '</div>'
        . '</body></html>';
}

function send_admin_guest_cancelled(array $hold): void {
    $env  = parse_env();
    $to   = setting('notify_email', 'reservations@tribalsand.com');
    $from = $env['MAIL_FROM'] ?? 'noreply@tribalsand.com';
    $site = rtrim($env['SITE_URL'] ?? '', '/');

    $subject = "[Guest Cancelled] {$hold['room_name']} — {$hold['guest_name']} — {$hold['check_in']} to {$hold['check_out']}";
    $body = implode("\n", [
        'A GUEST HAS CANCELLED THEIR OWN BOOKING',
        str_repeat('-', 40),
        "Guest:     {$hold['guest_name']}",
        "Email:     {$hold['guest_email']}",
        "Room:      {$hold['room_name']}",
        "Check-in:  {$hold['check_in']}",
        "Check-out: {$hold['check_out']}",
        '',
        'The dates have been freed and the guest has been notified.',
        '',
        "View holds: {$site}/admin/holds.php",
    ]);

    $inner = _email_lead('A guest has cancelled their own booking. The dates have been freed and the guest has been notified.')
        . _email_detail_block([
            ['Guest',     $hold['guest_name']  ?? ''],
            ['Email',     $hold['guest_email'] ?? ''],
            ['Room',      $hold['room_name']   ?? ''],
            ['Check-in',  $hold['check_in']    ?? ''],
            ['Check-out', $hold['check_out']   ?? ''],
        ], 'Cancelled booking')
        . _email_button('View holds', $site . '/admin/holds.php');
    $html = _email_shell('Guest cancelled a booking', $inner, $site);

    _dispatch_mail($to, $subject, $body, $from, $hold['guest_email'] ?? $from, $env, $html);
}

function send_hold_cancelled(array $hold, string $reason = 'cancelled'): void {
    $env   = parse_env();
    $from  = $env['MAIL_FROM'] ?? 'noreply@tribalsand.com';
    $site  = rtrim($env['SITE_URL'] ?? $env['APP_URL'] ?? 'https://tribalsand.com', '/');
    $reply = setting('notify_email', 'reservations@tribalsand.com');

    $is_expired = $reason === 'expired';
    $subject    = $is_expired
        ? "Hold Expired — {$hold['room_name']} — {$hold['check_in']}"
        : "Hold Cancelled — {$hold['room_name']} — {$hold['check_in']}";

    $intro = $is_expired
        ? "Unfortunately we were unable to confirm your hold request for {$hold['room_name']} within the 24-hour window."
        : "Your hold request for {$hold['room_name']} has been cancelled.";

    $body = implode("\n", [
        "Dear {$hold['guest_name']},",
        '',
        $intro,
        '',
        "Dates: {$hold['check_in']} to {$hold['check_out']}",
        '',
        'Please contact us to check alternative availability:',
        "Email: {$reply}",
        '',
        'Warm regards,',
        'Tribal Sand',
    ]);

    $inner = '<p style="margin:0 0 18px;font-size:15px">Dear <strong>' . _email_esc($hold['guest_name'] ?? 'Guest') . '</strong>,</p>'
        . _email_lead($intro)
        . _email_detail_block([
            ['Villa / Room', $hold['room_name'] ?? ''],
            ['Check-in',     $hold['check_in']  ?? ''],
            ['Check-out',    $hold['check_out'] ?? ''],
        ], 'Your dates')
        . '<p style="font-size:14px;color:#333;line-height:1.7;margin:18px 0 0">We’d love to help you find alternative dates — just reply to this email or write to '
            . '<a href="mailto:' . _email_esc($reply) . '" style="color:#1E5C6B">' . _email_esc($reply) . '</a>.</p>'
        . '<p style="font-size:14px;margin:24px 0 0">Warm regards,<br><strong>Tribal Sand</strong></p>';
    $html = _email_shell($is_expired ? 'We couldn’t confirm your dates' : 'Your hold has been cancelled', $inner, $site);

    _dispatch_mail($hold['guest_email'], $subject, $body, $from, $from, $env, $html);
}

// ── Shared HTML email template (teal header + card + footer) ─────
// Reused by every notification/transactional email so guest AND staff messages
// share the Tribal Sand design system (never plain-text). Keep in step with the
// guest-acknowledgement / trip-builder templates above.

function _email_esc(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

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

/** A short lead paragraph. */
function _email_lead(string $text): string {
    return '<p style="margin:0 0 4px;font-size:15px;line-height:1.6;color:#333">' . nl2br(_email_esc($text)) . '</p>';
}

function _dispatch_mail(string $to, string $subject, string $body, string $from, string $reply_to, array $env, string $html = ''): void {
    $headers  = "From: {$from}\r\n";
    $headers .= "Reply-To: {$reply_to}\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "MIME-Version: 1.0\r\n";

    if (!empty($env['RESEND_API_KEY'])) {
        send_resend($to, $subject, $body, $from, $reply_to, $env['RESEND_API_KEY'], $html);
    } elseif (($env['MAIL_DRIVER'] ?? '') === 'smtp') {
        send_smtp($to, $subject, $body, $headers, $env, $html);
    } elseif (($env['MAIL_DRIVER'] ?? '') === 'log') {
        log_mail_error("[DEV] To: {$to} | Subject: {$subject}\n{$body}");
    } else {
        if (!@mail($to, $subject, $body, $headers)) {
            log_mail_error("mail() failed sending '{$subject}' to {$to}");
        }
    }
}

function log_mail_error(string $message): void {
    $log = __DIR__ . '/../logs/mail.log';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents($log, $line, FILE_APPEND | LOCK_EX);
}

/** Notify admin of a guest change request. */
function send_change_request_notification(array $hold, array $req): void {
    $env  = parse_env();
    $from = $env['MAIL_FROM'] ?? 'noreply@tribalsand.com';
    $to   = setting('notify_email', 'reservations@tribalsand.com');
    $site = rtrim($env['SITE_URL'] ?? $env['APP_URL'] ?? 'https://tribalsand.com', '/');
    $admin= $site . '/admin/holds.php';
    $subject = "Change request — hold #{$hold['id']} ({$hold['guest_name']})";
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
    $text = implode("\n", $lines);
    $inner = _email_lead("{$hold['guest_name']} ({$hold['guest_email']}) requested a change to hold #{$hold['id']} — {$hold['room_name']}.")
        . _email_detail_block([
            ['New check-in',  ($req['check_in']  ?? '') !== '' ? $req['check_in']  : '—'],
            ['New check-out', ($req['check_out'] ?? '') !== '' ? $req['check_out'] : '—'],
            ['Guests',        (int)($req['guests'] ?? 0) > 0 ? (int)$req['guests'] : '—'],
            ['Note',          ($req['note'] ?? '') !== '' ? $req['note'] : '—'],
        ], 'Requested change')
        . _email_button('Review in admin', $admin);
    $html = _email_shell('Guest change request', $inner, $site);
    _dispatch_mail($to, $subject, $text, $from, $to, $env, $html);
}

/** Notify admin of a guest add-on request. */
function send_addon_request_notification(array $hold, array $addon): void {
    $env  = parse_env();
    $from = $env['MAIL_FROM'] ?? 'noreply@tribalsand.com';
    $to   = setting('notify_email', 'reservations@tribalsand.com');
    $site = rtrim($env['SITE_URL'] ?? $env['APP_URL'] ?? 'https://tribalsand.com', '/');
    $admin= $site . '/admin/holds.php';
    $subject = "Add-on request — hold #{$hold['id']} ({$hold['guest_name']})";
    $lines = [
        "Guest {$hold['guest_name']} ({$hold['guest_email']}) added a {$addon['kind']} to hold #{$hold['id']} — {$hold['room_name']}.",
        '',
        'Details: ' . (($addon['details'] ?? '') !== '' ? $addon['details'] : '—'),
        '',
        "Review in admin: {$admin}",
    ];
    $text = implode("\n", $lines);
    $inner = _email_lead("{$hold['guest_name']} ({$hold['guest_email']}) added a {$addon['kind']} to hold #{$hold['id']} — {$hold['room_name']}.")
        . _email_detail_block([
            ['Add-on',  $addon['kind'] ?? ''],
            ['Details', ($addon['details'] ?? '') !== '' ? $addon['details'] : '—'],
        ], 'Add-on request')
        . _email_button('Review in admin', $admin);
    $html = _email_shell('Guest add-on request', $inner, $site);
    _dispatch_mail($to, $subject, $text, $from, $to, $env, $html);
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

/** Guest acknowledgement ("request received, pending confirmation") + staff alert. */
function send_reservation_received(array $res): void {
    $env   = parse_env();
    $from  = $env['MAIL_FROM'] ?? 'noreply@tribalsand.com';
    $reply = setting('notify_email', 'reservations@tribalsand.com');
    $site  = rtrim($env['SITE_URL'] ?? $env['APP_URL'] ?? 'https://tribalsand.com', '/');

    $venue = (string)($res['venue_name'] ?? 'Tribal Sand');
    $when  = _reservation_when($res);
    $ref   = (string)($res['reference'] ?? '');
    $rows  = _reservation_rows($res);

    // ── Guest acknowledgement ──
    $guestEmail = trim((string)($res['guest_email'] ?? ''));
    if (filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
        $name    = trim((string)($res['guest_name'] ?? '')) ?: 'Guest';
        $subject = 'We’ve received your table request — ' . $venue;

        $textLines = [
            "Dear {$name},",
            '',
            "Thank you for your table request at {$venue}. It’s pending confirmation — a member of our team will be in touch shortly to confirm your reservation.",
            '',
            'YOUR REQUEST',
        ];
        foreach ($rows as [$k, $v]) if ($v !== '') $textLines[] = "  {$k}: {$v}";
        $textLines[] = '';
        if (($res['notes'] ?? '') !== '') { $textLines[] = 'Your note:'; $textLines[] = (string)$res['notes']; $textLines[] = ''; }
        $textLines[] = "If you need to change anything, reply to this email or write to {$reply}.";
        $textLines[] = '';
        $textLines[] = 'Warm regards,';
        $textLines[] = 'Tribal Sand';
        $text = implode("\n", $textLines);

        $inner = '<p style="margin:0 0 18px;font-size:15px">Dear <strong>' . _email_esc($name) . '</strong>,</p>'
            . _email_lead("Thank you for your table request at {$venue}. It’s <strong>pending confirmation</strong> — a member of our team will be in touch shortly to confirm your reservation.")
            . _email_detail_block($rows, 'Your request')
            . _email_message_block((string)($res['notes'] ?? ''), 'Your note')
            . '<p style="font-size:13px;color:#777;line-height:1.6;margin-top:24px">Need to change anything? Simply reply to this email, or write to '
                . '<a href="mailto:' . _email_esc($reply) . '" style="color:#1E5C6B">' . _email_esc($reply) . '</a>.</p>'
            . '<p style="font-size:14px;margin:24px 0 0">Warm regards,<br><strong>Tribal Sand</strong></p>';
        $html = _email_shell('Table request received', $inner, $site);

        _dispatch_mail($guestEmail, $subject, $text, $from, $reply, $env, $html);
    }

    // ── Staff alert ──
    $to = $reply;
    $subjectS = "[Reservation] {$venue} — " . trim((string)($res['guest_name'] ?? 'Guest')) . ($when ? " — {$when}" : '');
    $adminUrl = $site . '/admin/reservations.php';

    $staffRows = array_merge($rows, [
        ['Phone', $res['guest_phone'] ?? ''],
        ['Email', $res['guest_email'] ?? ''],
    ]);
    $textS = implode("\n", array_merge(
        ['NEW TABLE RESERVATION REQUEST', str_repeat('-', 40)],
        array_map(fn($r) => $r[1] !== '' ? "{$r[0]}: {$r[1]}" : '', $staffRows),
        ['', 'Note: ' . (($res['notes'] ?? '') !== '' ? $res['notes'] : '—'), '', "Review: {$adminUrl}"]
    ));
    $innerS = _email_lead('A new table reservation request has come in through the website. It’s pending — please confirm or cancel.')
        . _email_detail_block($staffRows, 'Reservation')
        . _email_message_block((string)($res['notes'] ?? ''), 'Guest note')
        . _email_button('Review reservations', $adminUrl);
    $htmlS = _email_shell('New reservation request', $innerS, $site);

    _dispatch_mail($to, $subjectS, $textS, $from, $guestEmail !== '' ? $guestEmail : $to, $env, $htmlS);
}

/** Guest confirmation email when staff approve a reservation. */
function send_reservation_confirmed(array $res): void {
    $to = trim((string)($res['guest_email'] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return;   // no guest email → nothing to send

    $env   = parse_env();
    $from  = $env['MAIL_FROM'] ?? 'noreply@tribalsand.com';
    $reply = setting('notify_email', 'reservations@tribalsand.com');
    $site  = rtrim($env['SITE_URL'] ?? $env['APP_URL'] ?? 'https://tribalsand.com', '/');

    $venue = (string)($res['venue_name'] ?? 'Tribal Sand');
    $name  = trim((string)($res['guest_name'] ?? '')) ?: 'Guest';
    $rows  = _reservation_rows($res);

    $subject = "Your table is confirmed — {$venue}";

    $textLines = [
        "Dear {$name},",
        '',
        "We’re delighted to confirm your table at {$venue}. We look forward to welcoming you.",
        '',
        'YOUR RESERVATION',
    ];
    foreach ($rows as [$k, $v]) if ($v !== '') $textLines[] = "  {$k}: {$v}";
    $textLines[] = '';
    $textLines[] = "If your plans change, please reply to this email or write to {$reply}.";
    $textLines[] = '';
    $textLines[] = 'Warm regards,';
    $textLines[] = 'Tribal Sand';
    $text = implode("\n", $textLines);

    $inner = '<p style="margin:0 0 18px;font-size:15px">Dear <strong>' . _email_esc($name) . '</strong>,</p>'
        . _email_lead("We’re delighted to confirm your table at {$venue}. We look forward to welcoming you.")
        . _email_detail_block($rows, 'Your reservation')
        . '<p style="font-size:13px;color:#777;line-height:1.6;margin-top:24px">If your plans change, simply reply to this email, or write to '
            . '<a href="mailto:' . _email_esc($reply) . '" style="color:#1E5C6B">' . _email_esc($reply) . '</a>.</p>'
        . '<p style="font-size:14px;margin:24px 0 0">Warm regards,<br><strong>Tribal Sand</strong></p>';
    $html = _email_shell('Table confirmed', $inner, $site);

    _dispatch_mail($to, $subject, $text, $from, $reply, $env, $html);
}

/** Best-effort front-desk notice on check-in completion. No-ops if mail is unconfigured. */
function send_checkin_completed(array $hold, ?array $data): void {
    $env = parse_env();
    $key = $env['RESEND_API_KEY'] ?? '';
    $from = $env['MAIL_FROM'] ?? '';
    $to  = $env['ADMIN_NOTIFY_EMAIL'] ?? $from;
    if ($key === '' || $from === '' || $to === '') return;     // not configured → silent
    $lines = [
        'Guest: ' . ($hold['guest_name'] ?? ''),
        'Room: '  . ($hold['room_name'] ?? ''),
        'Flight: ' . trim((string)($data['flight_number'] ?? '') . ' ' . (string)($data['arrival_airport'] ?? '')),
        'Arrival: ' . (string)($data['arrival_at'] ?? ''),
        'Transfer: ' . (($data['needs_transfer'] ?? null) ? ('yes — ' . (string)($data['transfer_details'] ?? '')) : 'no'),
        'Dietary: ' . (string)($data['dietary'] ?? ''),
        'Requests: ' . (string)($data['special_requests'] ?? ''),
    ];
    $adminUrl = site_url('/admin/booking.php?hold=' . (int)$hold['id'] . '&tab=checkin');
    $body = "Guest completed pre-check-in.\n\n" . implode("\n", $lines) . "\n\n" . $adminUrl;

    $inner = _email_lead('A guest has completed their pre-check-in.')
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
    $html = _email_shell('Pre-check-in complete', $inner, rtrim($env['SITE_URL'] ?? $env['APP_URL'] ?? 'https://tribalsand.com', '/'));

    try { send_resend($to, 'Pre-check-in complete — ' . ($hold['guest_name'] ?? ''), $body, $from, $from, $key, $html); }
    catch (Throwable $e) { error_log('[checkin] send_resend: ' . $e->getMessage()); }
}
