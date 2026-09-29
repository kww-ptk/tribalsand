<?php
declare(strict_types=1);
/**
 * Email Notifications Center tests. Run: php tests/email_log_logic.php
 *
 * Pure (always): the one-send-path guarantee (no email bypasses mail_send()),
 * registry completeness (every key used in code is registered, every default
 * wording only uses its own placeholders, every sample renders its own email),
 * placeholder fill + validation + escaping, the "Email the guest" default rule,
 * SES Message-ID parsing and event mapping, and mail_send() with the dev `log`
 * driver (skipped / invalid address / sent).
 *
 * DB (when reachable and add_email_log.sql / add_email_templates.sql have run;
 * one transaction, always rolled back): one log row per send with its key,
 * suppressed when switched off, skipped with the reason, SES events update the
 * row without downgrading, booking / enquiry panels, wording overrides
 * (property → global → default) with version history, and retention.
 */
$_SERVER['MAIL_DRIVER'] = 'log';          // never touch a real mail server
unset($_SERVER['RESEND_API_KEY'], $_ENV['RESEND_API_KEY']);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/confirm.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

$root = dirname(__DIR__);
$phpFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = str_replace('\\', '/', $f->getPathname());
    if (!str_ends_with($p, '.php')) continue;
    $rel = substr($p, strlen(str_replace('\\', '/', $root)) + 1);
    if (preg_match('~^(\.git|\.claude|vendor|node_modules|tests)/~', $rel)) continue;   // relative: the worktree itself lives under .claude/
    $phpFiles[$rel] = (string)file_get_contents($p);
}

// ── ONE send path ────────────────────────────────────────────────────────────
$bypass = [];
foreach ($phpFiles as $rel => $src) {
    $code = preg_replace('~^\s*(//|\*|#).*$~m', '', $src);   // ignore comment lines
    $code = preg_replace('~\'(?:[^\'\\\\\n]|\\\\.)*\'~', "''", $code);   // …and single-quoted strings (labels like 'PHP mail()')
    if (preg_match_all('~\b_dispatch_mail\s*\(~', $code, $m) && !in_array($rel, ['includes/mail.php', 'includes/mail-log.php'], true)) $bypass[] = "{$rel}: _dispatch_mail(";
    if (preg_match('~\b(send_resend|send_smtp)\s*\(~', $code) && $rel !== 'includes/mail.php') $bypass[] = "{$rel}: send_resend/send_smtp(";
    if (preg_match('~(?<![\w>$:])@?mail\s*\(~', $code) && $rel !== 'includes/mail.php') $bypass[] = "{$rel}: mail(";
}
check('no email bypasses mail_send(): ' . ($bypass ? implode('; ', $bypass) : 'clean'), !$bypass);

$mailSrc = preg_replace('~^\s*(//|\*|#).*$~m', '', $phpFiles['includes/mail.php']);
check('includes/mail.php only DEFINES _dispatch_mail (never calls it)',
      preg_match_all('~\b_dispatch_mail\s*\(~', $mailSrc) === 1 && str_contains($mailSrc, 'function _dispatch_mail('));
$logSrc = preg_replace('~^\s*(//|\*|#).*$~m', '', $phpFiles['includes/mail-log.php']);
check('mail_send() is the one caller of _dispatch_mail', preg_match_all('~\b_dispatch_mail\s*\(~', $logSrc) === 1);

// ── Registry completeness ────────────────────────────────────────────────────
$reg = email_registry();
$used = [];
foreach ($phpFiles as $rel => $src) {
    if (preg_match_all("~mail_send\\(\\s*'([a-z0-9_]+)'~", $src, $m)) foreach ($m[1] as $k) $used[$k] = $rel;
    if (preg_match_all("~email_field\\(\\s*'([a-z0-9_]+)'~", $src, $m)) foreach ($m[1] as $k) $used[$k] = $rel;
}
$used['ack_enquiry'] = $used['ack_hold'] = $used['ack_contact'] = $used['ack_agency'] = 'includes/mail.php';   // 'ack_' . $kind
$used['hold_expired'] = 'includes/mail.php';                                                                     // chosen by $reason
$unregistered = array_diff(array_keys($used), array_keys($reg));
check('every email key used in code is registered' . ($unregistered ? ': ' . implode(', ', $unregistered) : ''), !$unregistered);
// A key is "sent somewhere" when a sender names it — directly in mail_send() or
// through a variable ($key = 'reservation_confirmed'; mail_send($key, …)).
$unused = [];
foreach (array_keys($reg) as $k) {
    if (isset($used[$k])) continue;
    $found = false;
    foreach ($phpFiles as $rel => $src) {
        if ($rel !== 'includes/email-templates.php' && str_contains($src, "'{$k}'")) { $found = true; break; }
    }
    if (!$found) $unused[] = $k;
}
check('every registered email is sent somewhere' . ($unused ? ': ' . implode(', ', $unused) : ''), !$unused);

foreach ($reg as $k => $t) {
    $ok = in_array($t['audience'], ['guest', 'staff'], true) && ($t['trigger'] ?? '') !== '' && ($t['name'] ?? '') !== ''
       && !array_diff(array_keys($t['fields']), EMAIL_TEMPLATE_FIELDS) && isset($t['fields']['subject']);
    $bad = [];
    foreach ($t['fields'] as $f => $txt) {
        $bad = array_merge($bad, array_diff(email_placeholders_in($txt), array_keys(email_allowed_placeholders($k))));
    }
    check("registry {$k}: well-formed, defaults use only its placeholders" . ($bad ? ' — unknown: ' . implode(',', $bad) : ''), $ok && !$bad);
    check("registry {$k}: default wording passes validation", email_validate_fields($k, $t['fields']) === []);
    $msg = email_render_sample($k);
    check("sample {$k}: renders its own email with a subject and HTML", $msg && $msg['subject'] !== '' && str_contains($msg['html'], '<html'));
    if ($msg && $t['audience'] === 'guest' && $k !== 'password_reset') {
        check("sample {$k}: guest replies go to reservations@, never noreply", $msg['reply_to'] === 'reservations@tribalsand.com');
    }
}
check('capture mode sends and logs nothing', !isset($GLOBALS['__mail_capture']));

// ── Property-aware confirmation copy ─────────────────────────────────────────
$cap = mail_capture(fn() => send_hold_confirmed(['id' => 0, 'guest_name' => 'A', 'guest_email' => 'a@example.com', 'room_name' => 'Garden Room',
    'check_in' => '2026-12-01', 'check_out' => '2026-12-03', 'venue_id' => null, 'venue_name' => 'Maya Kobe']));
check('confirmation names the property, not a hardcoded town', $cap && str_contains($cap[0]['html'], 'Maya Kobe') && !str_contains($cap[0]['text'], 'Watamu'));
check('confirmation Reply-To is reservations@ (not MAIL_FROM)', $cap && $cap[0]['reply_to'] === 'reservations@tribalsand.com');

// ── Placeholders, validation, escaping ───────────────────────────────────────
check('fill: known placeholder', email_fill('Hi {{ guest_name }}!', ['guest_name' => 'Jo']) === 'Hi Jo!');
check('fill: unknown placeholder renders empty', email_fill('Hi {{nope}}.', []) === 'Hi .');
check('placeholders_in lists names once', email_placeholders_in('{{a}} {{b}} {{a}}') === ['a', 'b']);
$v = email_validate_fields('hold_confirmed', ['subject' => 'Hi {{made_up}}']);
check('validate: unknown placeholder refused', isset($v['subject']));
check('validate: HTML refused', isset(email_validate_fields('hold_confirmed', ['intro' => 'Hello <b>there</b>'])['intro']));
check('validate: multi-line subject refused', isset(email_validate_fields('hold_confirmed', ['subject' => "a\nb"])['subject']));
check('validate: field the email lacks refused', isset(email_validate_fields('password_reset', ['intro' => 'x'])['intro']));
check('validate: unclosed placeholder refused', isset(email_validate_fields('hold_confirmed', ['intro' => 'Hi {{guest_name'])['intro']));
check('validate: good wording passes', email_validate_fields('hold_confirmed', ['intro' => 'Welcome to **{{property_name}}**, {{guest_name}}.']) === []);
$rich = _email_rich('<script>x</script> **bold** mail reservations@tribalsand.com');
check('rich text: HTML escaped', !str_contains($rich, '<script>') && str_contains($rich, '&lt;script&gt;'));
check('rich text: **bold** and email link', str_contains($rich, '<strong>bold</strong>') && str_contains($rich, 'mailto:reservations@tribalsand.com'));
check('plain text drops ** markers', _email_plain('a **b** c') === 'a b c');
check('subject tidy: empty placeholder leaves no dangling dash',
      email_field('staff_new_lead', 'subject', ['type' => 'Contact', 'guest_name' => 'Bo', 'room_name' => '', 'date' => '1 Oct']) === '[Contact] Bo — 1 Oct');

// ── "Email the guest" default ────────────────────────────────────────────────
$d = fn(array $h) => email_hold_confirm_default($h + ['guest_email' => 'guest@example.com', 'submission_id' => 5, 'expires_at' => '2026-01-01']);
check('default: normal guest → on', $d([])['default'] === true);
check('default: no email → off and cannot send', $d(['guest_email' => ''])['default'] === false && $d(['guest_email' => ''])['can'] === false);
check('default: Booking.com relay → off', $d(['guest_email' => 'abc123@guest.booking.com'])['default'] === false);
check('default: Airbnb relay → off', $d(['guest_email' => 'x@guest.airbnb.com'])['default'] === false);
check('default: our own address → off', $d(['guest_email' => 'ops@tribalsand.com'])['default'] === false);
check('default: imported from a channel → off', $d(['ledger_source' => 'ota'])['default'] === false);
check('default: trade booking → on, names the agent', $d(['agent_id' => 3])['default'] === true && str_contains($d(['agent_id' => 3])['reason'], 'agent'));
check('default: staff-created → on with a warning', $d(['submission_id' => null, 'expires_at' => null])['default'] === true
      && str_contains($d(['submission_id' => null, 'expires_at' => null])['reason'], 'staff'));
check('relay check ignores look-alike domains', email_address_is_relay_or_internal('me@notbooking.com') === null);

// ── SES ──────────────────────────────────────────────────────────────────────
check('SES Message-ID parsed from 250 Ok', _smtp_ses_message_id('250 Ok 0102018f2a3b4c5d-6e7f-4a5b-9c8d-000000') === '0102018f2a3b4c5d-6e7f-4a5b-9c8d-000000');
check('non-SES 250 reply → no id', _smtp_ses_message_id('250 2.0.0 queued as 12345') === '');
check('event: Delivery → delivered', email_ses_event_status(['eventType' => 'Delivery'])[0] === 'delivered');
check('event: permanent bounce → bounced', email_ses_event_status(['eventType' => 'Bounce', 'bounce' => ['bounceType' => 'Permanent']])[0] === 'bounced');
check('event: transient bounce → note only', email_ses_event_status(['eventType' => 'Bounce', 'bounce' => ['bounceType' => 'Transient']])[0] === null);
check('event: Complaint → complained', email_ses_event_status(['notificationType' => 'Complaint'])[0] === 'complained');
check('event: Reject → failed', email_ses_event_status(['eventType' => 'Reject', 'reject' => ['reason' => 'Bad content']])[0] === 'failed');

// Everything from here writes (log rows, settings) — when a DB is reachable it all
// runs inside ONE transaction that is rolled back at the end, so the test never
// leaves a row behind on any database it is pointed at.
$dbOk = false;
try { db()->query('SELECT 1'); $dbOk = true; } catch (Throwable $e) {}
if ($dbOk) db()->beginTransaction();

// ── Reusable danger confirmation ─────────────────────────────────────────────
check('confirm: typed word accepted', typed_confirmation_ok('DELETE', ['confirm_text' => ' DELETE ']));
check('confirm: wrong / missing / lowercase word refused', !typed_confirmation_ok('DELETE', ['confirm_text' => 'delete'])
      && !typed_confirmation_ok('DELETE', []) && !typed_confirmation_ok('', ['confirm_text' => '']));
$attrs = danger_confirm_attrs('Stops "it" <b>', 'Delete?', 'Delete', 'DELETE');
check('confirm: attributes escaped and complete', str_contains($attrs, 'data-confirm-type="DELETE"') && str_contains($attrs, '&quot;it&quot; &lt;b&gt;')
      && str_contains($attrs, 'data-confirm-title="Delete?"') && str_contains($attrs, 'data-confirm-label="Delete"'));
check('confirm: plain confirm has no type word', !str_contains(danger_confirm_attrs('Sure?'), 'data-confirm-type'));
$layoutJs = (string)file_get_contents(__DIR__ . '/../admin/_layout_end.php');
check('confirm: the shared dialog posts the typed word as confirm_text', str_contains($layoutJs, "h.name = 'confirm_text'") && str_contains($layoutJs, 'data-confirm-type'));
// ── mail_send() with the dev log driver ──────────────────────────────────────
$r = mail_send('hold_confirmed', ['to' => 'guest@example.com', 'subject' => 'S', 'text' => 'T'], ['skip_reason' => 'Staff unticked']);
check('skip_reason → skipped, nothing sent', $r['ok'] === false && $r['status'] === 'skipped');
$r = mail_send('hold_confirmed', ['to' => 'not-an-email', 'subject' => 'S', 'text' => 'T']);
check('invalid address → skipped', $r['ok'] === false && $r['status'] === 'skipped');
$r = mail_send('password_reset', ['to' => 'guest@example.com', 'subject' => 'S', 'text' => 'T']);   // locked → no settings read needed
check('log driver → sent', $r['ok'] === true && $r['status'] === 'sent');
check('send_admin_reply still refuses an empty message', send_admin_reply(['id' => 1, 'guest_email' => 'a@b.com'], '  ')['ok'] === false);
check('hold_email_after_action: unticked → "guest not emailed"',
      hold_email_after_action('confirm', ['id' => 0, 'guest_name' => 'A', 'guest_email' => 'a@example.com', 'room_name' => 'R',
                                          'check_in' => '2026-12-01', 'check_out' => '2026-12-02'], false, 'test') === 'guest not emailed.');

// ── DB round-trip (rolled back) ──────────────────────────────────────────────
if (!$dbOk || !email_log_supported()) {
    echo "\nSKIP  DB assertions (" . (!$dbOk ? 'no database reachable' : 'run add_email_log.sql') . ")\n";
} else {
    $pdo = db();
    try {
        $count = fn(string $k) => (int) db_query('SELECT COUNT(*) FROM email_log WHERE template_key = :k', [':k' => $k])->fetchColumn();
        $before = $count('staff_guest_cancelled');
        send_admin_guest_cancelled(['id' => 0, 'guest_name' => 'Zed', 'guest_email' => 'zed@example.com', 'room_name' => 'R', 'check_in' => '2026-12-01', 'check_out' => '2026-12-02']);
        check('db: one send → exactly one row with its key', $count('staff_guest_cancelled') === $before + 1);
        $row = db_query("SELECT * FROM email_log WHERE template_key = 'staff_guest_cancelled' ORDER BY id DESC LIMIT 1")->fetch();
        check('db: row carries status, snapshot and trigger source', $row['status'] === 'sent' && str_contains((string)$row['html_snapshot'], 'Zed') && $row['trigger_source'] !== '');
        check('db: CLI send is attributed to the system', $row['triggered_by'] === 'system');

        set_setting('email_enabled_staff_guest_cancelled', '0');
        $r = send_admin_guest_cancelled(['id' => 0, 'guest_name' => 'Zed', 'guest_email' => 'zed@example.com', 'room_name' => 'R', 'check_in' => '2026-12-01', 'check_out' => '2026-12-02']);
        $row = db_query("SELECT status FROM email_log WHERE template_key = 'staff_guest_cancelled' ORDER BY id DESC LIMIT 1")->fetchColumn();
        check('db: switched off → logged as suppressed', $row === 'suppressed');

        // Delete / restore
        $threw = false;
        try { email_template_delete('password_reset', null); } catch (InvalidArgumentException $e) { $threw = true; }
        check('db: a locked email can’t be deleted', $threw && !email_template_deleted('password_reset'));
        set_setting('email_to_staff_addon_request', 'x@example.com');
        email_template_delete('staff_addon_request', null);
        check('db: deleted → flagged, off, recipients cleared', email_template_deleted('staff_addon_request')
              && !email_template_enabled('staff_addon_request') && email_recipient_override('staff_addon_request') === []);
        send_addon_request_notification(['id' => 0, 'guest_name' => 'Y', 'guest_email' => 'y@example.com', 'room_name' => 'R'], ['kind' => 'transfer']);
        $row = db_query("SELECT status, note FROM email_log WHERE template_key = 'staff_addon_request' ORDER BY id DESC LIMIT 1")->fetch();
        check('db: a deleted email is never sent — logged as Deleted', $row && $row['status'] === 'suppressed' && str_contains((string)$row['note'], 'Deleted'));
        $r = mail_send('staff_addon_request', ['to' => 'z@example.com', 'subject' => 'S', 'text' => 'T'], ['force' => true]);
        check('db: a test send (force) still works for a deleted email', $r['status'] === 'sent');
        email_template_restore('staff_addon_request');
        check('db: restore → back on', !email_template_deleted('staff_addon_request') && email_template_enabled('staff_addon_request'));

        set_setting('email_to_staff_change_request', 'a@example.com, b@example.com, not-an-email');
        $before = $count('staff_change_request');
        send_change_request_notification(['id' => 0, 'guest_name' => 'Y', 'guest_email' => 'y@example.com', 'room_name' => 'R'], ['note' => 'x']);
        check('db: recipient override → one row per valid address', $count('staff_change_request') === $before + 2);

        $mid = 'test-' . bin2hex(random_bytes(6));
        $r = mail_send('password_reset', ['to' => 'p@example.com', 'subject' => 'S', 'text' => 'secret-link-123', 'html' => '<a href="x?t=secret-link-123">x</a>'],
                       ['redact' => ['secret-link-123']]);
        $lid = $r['log_ids'][0] ?? 0;
        $snap = db_query('SELECT html_snapshot, text_snapshot FROM email_log WHERE id = :i', [':i' => $lid])->fetch();
        check('db: redacted secrets never reach the log', $snap && !str_contains($snap['html_snapshot'] . $snap['text_snapshot'], 'secret-link-123'));

        db_query('UPDATE email_log SET provider_id = :p WHERE id = :i', [':p' => $mid, ':i' => $lid]);
        email_log_apply_ses_event(['eventType' => 'Delivery', 'mail' => ['messageId' => $mid]]);
        check('db: SES Delivery → delivered', db_query('SELECT status FROM email_log WHERE id = :i', [':i' => $lid])->fetchColumn() === 'delivered');
        email_log_apply_ses_event(['eventType' => 'Complaint', 'mail' => ['messageId' => $mid]]);
        email_log_apply_ses_event(['eventType' => 'Delivery', 'mail' => ['messageId' => $mid]]);
        check('db: a late Delivery never hides a complaint', db_query('SELECT status FROM email_log WHERE id = :i', [':i' => $lid])->fetchColumn() === 'complained');

        $sid = (int) db_query('SELECT COALESCE(MAX(id), 0) + 900000 FROM submissions')->fetchColumn();
        mail_send('ack_contact', ['to' => 'c@example.com', 'subject' => 'S', 'text' => 'T'], ['submission_id' => $sid]);
        check('db: enquiry panel lists its emails', count(email_log_for_submission($sid)) === 1);
        check('db: scoped account never sees a property-less row', count(email_log_for_submission($sid, [999999])) === 0);

        db_query("UPDATE email_log SET created_at = now() - interval '200 days' WHERE id = :i", [':i' => $lid]);
        check('db: retention clears old bodies', email_log_prune(180) >= 1
              && db_query('SELECT html_snapshot FROM email_log WHERE id = :i', [':i' => $lid])->fetchColumn() === null);

        if (email_templates_supported()) {
            $vid = (int) (db_query('SELECT id FROM venues ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
            email_template_save('hold_confirmed', null, ['subject' => 'Global {{room_name}}', 'intro' => email_template('hold_confirmed')['fields']['intro']], null);
            $g = email_template_override_row('hold_confirmed', null);
            check('db: saving the default text stores NULL (keeps following the code)', $g && $g['subject'] === 'Global {{room_name}}' && $g['intro'] === null);
            // The override cache is per request; read through a fresh query.
            $rows = db_query("SELECT subject FROM email_template_overrides WHERE template_key = 'hold_confirmed' AND venue_id IS NULL")->fetchColumn();
            check('db: global override stored', $rows === 'Global {{room_name}}');
            if ($vid) {
                email_template_save('hold_confirmed', $vid, ['subject' => 'Property {{room_name}}'], null);
                check('db: property override stored separately', (bool) email_template_override_row('hold_confirmed', $vid));
                email_template_reset('hold_confirmed', $vid, null);
                check('db: reset removes the property override', email_template_override_row('hold_confirmed', $vid) === null);
            }
            $vers = email_template_versions('hold_confirmed', null);
            check('db: every save writes a version', count($vers) >= 1 && $vers[0]['action'] === 'save');
            $threw = false;
            try { email_template_save('hold_confirmed', null, ['subject' => 'Bad {{nope}}'], null); } catch (InvalidArgumentException $e) { $threw = true; }
            check('db: save refuses unknown placeholders', $threw);
        } else {
            echo "SKIP  wording overrides (run add_email_templates.sql)\n";
        }
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}
if ($dbOk && db()->inTransaction()) db()->rollBack();

echo "\n" . ($failures ? "{$failures} FAILED" : 'ALL PASSED') . "\n";
exit($failures ? 1 : 0);
