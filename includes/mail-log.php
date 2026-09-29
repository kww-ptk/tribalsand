<?php
/**
 * Email Notifications Center — the ONE send path and the send log.
 *
 * mail_send() is the only function that hands an email to the mail server. It
 * checks the owner's on/off switch, applies a staff-alert recipient override,
 * sends through _dispatch_mail() (SES SMTP in production) and writes exactly one
 * email_log row per recipient — including emails it decided NOT to send
 * (suppressed / skipped), so "who got which email, and why" always has an
 * answer. tests/email_log_logic.php asserts nothing else calls _dispatch_mail().
 *
 * Fail-soft both ways: a log failure never blocks a send, a send failure never
 * throws into the booking flow. Pre-migration (no email_log table) it still
 * sends, it just can't log.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email-templates.php';

/** Statuses, in the order the log filter lists them. */
const EMAIL_LOG_STATUSES = ['sent', 'delivered', 'failed', 'bounced', 'complained', 'suppressed', 'skipped'];

function email_log_supported(): bool {
    static $s = null;
    if ($s !== null) return $s;
    try { return $s = (bool) db_query("SELECT to_regclass('public.email_log') IS NOT NULL")->fetchColumn(); }
    catch (Throwable $e) { return $s = false; }
}

// ── Capture mode (previews) ──────────────────────────────────────────────

/**
 * Run $fn with sending switched off and return every message mail_send() was
 * asked to send, instead of sending or logging it. Previews call the REAL
 * senders this way, so what the owner sees is exactly what a guest gets.
 */
function mail_capture(callable $fn): array {
    $GLOBALS['__mail_capture'] = [];
    try { $fn(); }
    finally { $out = $GLOBALS['__mail_capture']; unset($GLOBALS['__mail_capture']); }
    return $out;
}

function mail_capture_active(): bool {
    return isset($GLOBALS['__mail_capture']) && is_array($GLOBALS['__mail_capture']);
}

// ── Who / where triggered this ───────────────────────────────────────────

/** ['triggered_by' => admin|guest|system|pos, 'admin_id' => ?int]. */
function mail_actor(): array {
    if (PHP_SAPI === 'cli') return ['triggered_by' => 'system', 'admin_id' => null];
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (!empty($_SESSION['admin_id']))    return ['triggered_by' => 'admin', 'admin_id' => (int)$_SESSION['admin_id']];
        if (!empty($_SESSION['pos_user_id'])) return ['triggered_by' => 'pos',   'admin_id' => (int)$_SESSION['pos_user_id']];
    }
    return ['triggered_by' => 'guest', 'admin_id' => null];
}

/** The running script relative to the project root, e.g. "admin/holds.php". */
function mail_script_path(): string {
    $f = (string)($_SERVER['SCRIPT_FILENAME'] ?? ($_SERVER['argv'][0] ?? ''));
    $root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    $real = $f !== '' ? (realpath($f) ?: $f) : '';
    if ($real !== '' && stripos($real, $root) === 0) $real = substr($real, strlen($root));
    $real = ltrim(str_replace('\\', '/', $real), '/');
    return $real !== '' ? $real : ltrim((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/');
}

// ── Sending ──────────────────────────────────────────────────────────────

/**
 * Send one email. $msg: to, subject, text, html, [reply_to], [from].
 * $ctx: hold_id, submission_id, reservation_id, pos_sale_id, venue_id,
 *       trigger (short action label appended to the script, e.g. "confirm"),
 *       triggered_by (override), skip_reason (log as skipped, send nothing),
 *       force (bypass the on/off switch — test sends), note,
 *       redact (strings blanked out of the stored snapshots — e.g. a reset token).
 * Returns ['ok' => bool, 'status' => string, 'error' => string, 'log_ids' => int[]].
 */
function mail_send(string $key, array $msg, array $ctx = []): array {
    $t        = email_template($key);
    $audience = (string)($ctx['audience'] ?? ($t['audience'] ?? 'guest'));
    $env      = parse_env();
    $from     = (string)($msg['from'] ?? ($env['MAIL_FROM'] ?? 'Tribal Sand <noreply@tribalsand.com>'));
    $replyTo  = (string)($msg['reply_to'] ?? ($audience === 'guest' ? email_guest_reply_to() : $from));
    $subject  = trim(preg_replace('~[\r\n]+~', ' ', (string)($msg['subject'] ?? '')));
    $text     = (string)($msg['text'] ?? '');
    $html     = (string)($msg['html'] ?? '');
    $to       = trim((string)($msg['to'] ?? ''));

    if (mail_capture_active()) {
        $GLOBALS['__mail_capture'][] = ['key' => $key, 'to' => $to, 'subject' => $subject, 'text' => $text,
                                        'html' => $html, 'reply_to' => $replyTo, 'from' => $from, 'audience' => $audience];
        return ['ok' => true, 'status' => 'captured', 'error' => '', 'log_ids' => []];
    }

    $recipients = [$to];
    $overridden = false;
    if ($audience === 'staff' && empty($ctx['force'])) {
        $ov = email_recipient_override($key);
        if ($ov) { $recipients = $ov; $overridden = true; }
    }

    $results = [];
    foreach ($recipients as $rcpt) {
        $row = ['status' => 'sent', 'provider' => '', 'provider_id' => null, 'error' => null, 'note' => $ctx['note'] ?? null];
        if (!empty($ctx['skip_reason'])) {
            $row['status'] = 'skipped';
            $row['note']   = (string)$ctx['skip_reason'];
        } elseif (!filter_var($rcpt, FILTER_VALIDATE_EMAIL)) {
            $row['status'] = 'skipped';
            $row['note']   = $rcpt === '' ? 'No email address.' : 'Not a valid email address.';
        } elseif (empty($ctx['force']) && !email_template_enabled($key)) {
            $row['status'] = 'suppressed';
            $row['note']   = 'Switched off in Admin → Emails.';
        } else {
            $meta = [];
            $GLOBALS['__mail_last_error'] = '';
            try {
                $ok = _dispatch_mail($rcpt, $subject, $text, $from, $replyTo, $env, $html, $meta);
            } catch (Throwable $e) {
                $ok = false;
                $GLOBALS['__mail_last_error'] = $e->getMessage();
            }
            $row['provider']    = (string)($meta['provider'] ?? '');
            $row['provider_id'] = ($meta['provider_id'] ?? '') !== '' ? (string)$meta['provider_id'] : null;
            if (!$ok) {
                $row['status'] = 'failed';
                $row['error']  = (string)($GLOBALS['__mail_last_error'] ?: 'The mail server did not accept the message.');
            }
            if ($overridden) $row['note'] = trim(($row['note'] ?? '') . ' Recipient set in Admin → Emails.');
        }
        $row['log_id'] = email_log_write($key, $audience, $rcpt, $subject, $row, $ctx, $html, $text);
        $results[] = $row;
    }

    $sent = array_values(array_filter($results, fn($r) => $r['status'] === 'sent'));
    $first = $sent[0] ?? $results[0];
    return [
        'ok'      => (bool)$sent,
        'status'  => $first['status'],
        'error'   => (string)($first['error'] ?? ($first['status'] === 'sent' ? '' : ($first['note'] ?? ''))),
        'log_ids' => array_values(array_filter(array_map(fn($r) => $r['log_id'], $results))),
    ];
}

/** Resolve the property a logged email belongs to (for manager scoping). */
function email_log_resolve_venue(array $ctx): ?int {
    if (!empty($ctx['venue_id'])) return (int)$ctx['venue_id'];
    try {
        if (!empty($ctx['hold_id'])) {
            $v = db_query('SELECT r.venue_id FROM holds h JOIN units u ON u.id = h.unit_id
                             JOIN rooms r ON r.id = ' . hold_room_id_sql('h', 'u') . ' WHERE h.id = :h',
                          [':h' => (int)$ctx['hold_id']])->fetchColumn();
            if ($v) return (int)$v;
        }
        if (!empty($ctx['submission_id'])) {
            $v = db_query('SELECT r.venue_id FROM submissions s JOIN rooms r ON r.id = s.room_id WHERE s.id = :s',
                          [':s' => (int)$ctx['submission_id']])->fetchColumn();
            if ($v) return (int)$v;
        }
        if (!empty($ctx['reservation_id'])) {
            $v = db_query('SELECT venue_id FROM reservations WHERE id = :r', [':r' => (int)$ctx['reservation_id']])->fetchColumn();
            if ($v) return (int)$v;
        }
    } catch (Throwable $e) { /* best-effort */ }
    return null;
}

/**
 * Write one log row. Never throws. Inside a caller's transaction it runs in a
 * SAVEPOINT — in Postgres a failed statement aborts the whole transaction, and a
 * log write must never take a booking down with it.
 */
function email_log_write(string $key, string $audience, string $to, string $subject, array $row, array $ctx, string $html, string $text): ?int {
    if (!email_log_supported()) {
        if ($row['status'] !== 'sent') {
            log_mail_error("[{$key}] {$row['status']} to {$to}: " . ($row['error'] ?? $row['note'] ?? ''));
        }
        return null;
    }
    $pdo = db();
    $inTx = $pdo->inTransaction();
    try {
        if ($inTx) $pdo->exec('SAVEPOINT email_log_write');
        foreach ((array)($ctx['redact'] ?? []) as $secret) {
            if ((string)$secret === '') continue;
            $html = str_replace([(string)$secret, htmlspecialchars((string)$secret, ENT_QUOTES, 'UTF-8')], '[hidden]', $html);
            $text = str_replace((string)$secret, '[hidden]', $text);
        }
        $actor = mail_actor();
        if (!empty($ctx['triggered_by']) && in_array($ctx['triggered_by'], ['admin', 'guest', 'system', 'pos'], true)) {
            $actor['triggered_by'] = $ctx['triggered_by'];
            if ($ctx['triggered_by'] === 'system') $actor['admin_id'] = null;
        }
        $source = mail_script_path() . (!empty($ctx['trigger']) ? ':' . $ctx['trigger'] : '');
        $holdId = !empty($ctx['hold_id']) ? (int)$ctx['hold_id'] : null;
        $subId  = !empty($ctx['submission_id']) ? (int)$ctx['submission_id'] : null;
        if ($holdId && !$subId) {
            $s = db_query('SELECT submission_id FROM holds WHERE id = :h', [':h' => $holdId])->fetchColumn();
            if ($s) $subId = (int)$s;
        }
        $id = db_query(
            'INSERT INTO email_log (template_key, audience, to_email, subject, status, provider, provider_id, error,
                                    hold_id, submission_id, reservation_id, pos_sale_id, venue_id,
                                    triggered_by, admin_id, trigger_source, note, html_snapshot, text_snapshot)
             VALUES (:k, :a, :to, :s, :st, :p, :pid, :err, :h, :sub, :res, :pos, :v, :tb, :aid, :src, :note, :html, :text)
             RETURNING id',
            [':k' => substr($key, 0, 64), ':a' => $audience === 'staff' ? 'staff' : 'guest',
             ':to' => substr($to, 0, 320), ':s' => $subject, ':st' => $row['status'],
             ':p' => substr((string)$row['provider'], 0, 16), ':pid' => $row['provider_id'], ':err' => $row['error'],
             ':h' => $holdId, ':sub' => $subId,
             ':res' => !empty($ctx['reservation_id']) ? (int)$ctx['reservation_id'] : null,
             ':pos' => !empty($ctx['pos_sale_id']) ? (int)$ctx['pos_sale_id'] : null,
             ':v' => email_log_resolve_venue($ctx + ['hold_id' => $holdId, 'submission_id' => $subId]),
             ':tb' => $actor['triggered_by'], ':aid' => $actor['admin_id'], ':src' => substr($source, 0, 160),
             ':note' => $row['note'] !== null && trim((string)$row['note']) !== '' ? trim((string)$row['note']) : null,
             ':html' => $html !== '' ? $html : null, ':text' => $text !== '' ? $text : null]
        )->fetchColumn();
        if ($inTx) $pdo->exec('RELEASE SAVEPOINT email_log_write');
        return $id ? (int)$id : null;
    } catch (Throwable $e) {
        if ($inTx) { try { $pdo->exec('ROLLBACK TO SAVEPOINT email_log_write'); $pdo->exec('RELEASE SAVEPOINT email_log_write'); } catch (Throwable $ignored) {} }
        error_log('[email-log] ' . $e->getMessage());
        return null;
    }
}

// ── Reading the log ──────────────────────────────────────────────────────

/** Human label + badge class for a status. */
function email_status_badge(string $status): array {
    return match ($status) {
        'sent'       => ['Sent', 'badge--blue'],
        'delivered'  => ['Delivered', 'badge--green'],
        'failed'     => ['Failed', 'badge--red'],
        'bounced'    => ['Bounced', 'badge--red'],
        'complained' => ['Marked as spam', 'badge--red'],
        'suppressed' => ['Switched off', 'badge--grey'],
        'skipped'    => ['Not sent', 'badge--orange'],
        default      => [ucfirst($status), 'badge--grey'],
    };
}

/** Name of a template for display (falls back to the key). */
function email_template_name(string $key): string {
    return (string)(email_template($key)['name'] ?? $key);
}

/**
 * SQL condition limiting the log to what an account may see. Owner (null
 * scope) sees everything; a scoped account sees rows of its properties only —
 * never staff-general rows with no property (password resets, etc.).
 */
function email_log_scope_sql(?array $venueIds, string $alias = 'l'): string {
    if ($venueIds === null) return 'TRUE';
    if (!$venueIds) return 'FALSE';
    return "{$alias}.venue_id IN (" . implode(',', array_map('intval', $venueIds)) . ')';
}

/** Emails linked to a booking (every room of its group) — newest first. */
function email_log_for_hold(int $holdId, ?array $venueIds = null, int $limit = 50): array {
    if (!email_log_supported() || $holdId <= 0) return [];
    try {
        $ids = function_exists('hold_group_ids') ? hold_group_ids($holdId) : [$holdId];
        $sub = (int) (db_query('SELECT submission_id FROM holds WHERE id = :h', [':h' => $holdId])->fetchColumn() ?: 0);
        $where = 'l.hold_id IN (' . implode(',', array_map('intval', $ids)) . ')' . ($sub ? ' OR l.submission_id = ' . $sub : '');
        return db_query("SELECT l.id, l.template_key, l.audience, l.to_email, l.subject, l.status, l.note, l.error,
                                l.triggered_by, l.created_at, l.event_at, a.name AS admin_name
                           FROM email_log l LEFT JOIN admin_users a ON a.id = l.admin_id
                          WHERE ({$where}) AND " . email_log_scope_sql($venueIds) . "
                          ORDER BY l.created_at DESC, l.id DESC LIMIT " . max(1, $limit))->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** Emails linked to an enquiry / submission — newest first. */
function email_log_for_submission(int $submissionId, ?array $venueIds = null, int $limit = 50): array {
    if (!email_log_supported() || $submissionId <= 0) return [];
    try {
        return db_query("SELECT l.id, l.template_key, l.audience, l.to_email, l.subject, l.status, l.note, l.error,
                                l.triggered_by, l.created_at, l.event_at, a.name AS admin_name
                           FROM email_log l LEFT JOIN admin_users a ON a.id = l.admin_id
                          WHERE (l.submission_id = :s OR l.hold_id IN (SELECT id FROM holds WHERE submission_id = :s))
                            AND " . email_log_scope_sql($venueIds) . "
                          ORDER BY l.created_at DESC, l.id DESC LIMIT " . max(1, $limit), [':s' => $submissionId])->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** Per-template stats for the catalogue: last sent, 30-day count, 30-day failures. */
function email_log_template_stats(): array {
    if (!email_log_supported()) return [];
    try {
        $rows = db_query("SELECT template_key,
                                 MAX(created_at) FILTER (WHERE status IN ('sent','delivered','bounced','complained')) AS last_sent,
                                 COUNT(*) FILTER (WHERE status IN ('sent','delivered','bounced','complained') AND created_at > now() - interval '30 days') AS sent_30,
                                 COUNT(*) FILTER (WHERE status IN ('failed','bounced','complained') AND created_at > now() - interval '30 days') AS failed_30,
                                 COUNT(*) FILTER (WHERE status IN ('suppressed','skipped') AND created_at > now() - interval '30 days') AS held_30
                            FROM email_log WHERE trigger_source NOT LIKE '%:test'   -- test sends from the preview page don't count
                           GROUP BY template_key")->fetchAll();
    } catch (Throwable $e) { return []; }
    $out = [];
    foreach ($rows as $r) $out[$r['template_key']] = $r;
    return $out;
}

/**
 * The "Emails sent" panel used on the booking workspace and the enquiry page.
 * $rows from email_log_for_hold() / email_log_for_submission().
 */
function email_log_panel_html(array $rows, bool $canOpen): string {
    if (!email_log_supported()) return '';
    $h = '<div class="card" style="margin-top:16px"><div class="card__head"><span class="card__title">Emails sent</span>'
       . ($canOpen ? '<a href="/admin/email-log.php" class="text-muted" style="font-size:12px">Full log</a>' : '') . '</div>';
    if (!$rows) {
        return $h . '<div class="card__body" style="padding:14px 18px"><span class="text-muted" style="font-size:13px">No emails logged for this yet. (Emails sent before the log was switched on aren’t listed.)</span></div></div>';
    }
    $h .= '<div class="table-wrap"><table class="data-table"><thead><tr><th>When</th><th>Email</th><th>To</th><th>Status</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        [$lbl, $cls] = email_status_badge((string)$r['status']);
        $who = match ($r['triggered_by']) {
            'admin', 'pos' => $r['admin_name'] ? 'by ' . $r['admin_name'] : 'by staff',
            'guest'  => 'by the guest',
            default  => 'automatic',
        };
        $name = e(email_template_name((string)$r['template_key']));
        $h .= '<tr><td style="white-space:nowrap;font-size:12px">' . e(date('j M Y, H:i', strtotime((string)$r['created_at']))) . '</td>'
            . '<td>' . ($canOpen ? '<a href="/admin/email-log.php?id=' . (int)$r['id'] . '">' . $name . '</a>' : $name)
            . '<div class="text-muted" style="font-size:12px">' . e($who) . '</div></td>'
            . '<td style="font-size:12px">' . e((string)$r['to_email']) . '</td>'
            . '<td><span class="badge ' . $cls . '">' . e($lbl) . '</span>'
            . (($r['note'] ?? '') !== '' || ($r['error'] ?? '') !== ''
                ? '<div class="text-muted" style="font-size:11px;margin-top:3px;max-width:260px">' . e((string)($r['error'] ?: $r['note'])) . '</div>' : '')
            . '</td></tr>';
    }
    return $h . '</tbody></table></div></div>';
}

// ── Retention ────────────────────────────────────────────────────────────

/** Drop rendered bodies older than $days (the row stays). Returns rows pruned. */
function email_log_prune(int $days = 180): int {
    if (!email_log_supported()) return 0;
    $st = db_query("UPDATE email_log SET html_snapshot = NULL, text_snapshot = NULL
                     WHERE created_at < now() - make_interval(days => :d)
                       AND (html_snapshot IS NOT NULL OR text_snapshot IS NOT NULL)", [':d' => max(1, $days)]);
    return $st->rowCount();
}

// ── Delivery events (Phase 1b) ───────────────────────────────────────────

/**
 * Map an SES event to a log status. Pure. Delivery → delivered, Bounce →
 * bounced (Permanent) or stays sent with a note (Transient — SES keeps
 * retrying), Complaint → complained, Reject / Rendering Failure → failed.
 * Returns [status|null, note].
 */
function email_ses_event_status(array $event): array {
    $type = (string)($event['eventType'] ?? $event['notificationType'] ?? '');
    switch ($type) {
        case 'Delivery':
            return ['delivered', ''];
        case 'Bounce':
            $b = $event['bounce'] ?? [];
            $why = trim(($b['bounceType'] ?? '') . ' / ' . ($b['bounceSubType'] ?? ''), ' /');
            $diag = (string)($b['bouncedRecipients'][0]['diagnosticCode'] ?? '');
            $note = 'Bounce: ' . $why . ($diag !== '' ? ' — ' . $diag : '');
            return [($b['bounceType'] ?? '') === 'Transient' ? null : 'bounced', $note];
        case 'Complaint':
            return ['complained', 'The recipient marked this email as spam.'];
        case 'Reject':
            return ['failed', 'SES rejected the message: ' . (string)($event['reject']['reason'] ?? '')];
        case 'Rendering Failure':
            return ['failed', 'SES could not render the message.'];
        case 'DeliveryDelay':
            return [null, 'Delivery delayed: ' . (string)($event['deliveryDelay']['delayType'] ?? '')];
        default:
            return [null, ''];
    }
}

/**
 * Apply an SES event to the matching log rows (keyed on provider_id = the SES
 * Message-ID). Later events never downgrade: a bounce/complaint after
 * "delivered" wins, a late "delivered" never overwrites a bounce. Returns rows updated.
 */
function email_log_apply_ses_event(array $event): int {
    if (!email_log_supported()) return 0;
    $mid = trim((string)($event['mail']['messageId'] ?? ''));
    if ($mid === '') return 0;
    [$status, $note] = email_ses_event_status($event);
    $rank = ['sent' => 1, 'delivered' => 2, 'failed' => 3, 'bounced' => 3, 'complained' => 4];
    $rows = db_query('SELECT id, status FROM email_log WHERE provider_id = :p', [':p' => $mid])->fetchAll();
    $n = 0;
    foreach ($rows as $r) {
        $cur = (string)$r['status'];
        $new = $status !== null && ($rank[$status] ?? 0) > ($rank[$cur] ?? 0) ? $status : $cur;
        if ($new === $cur && $note === '') continue;
        db_query("UPDATE email_log SET status = :s, event_at = now(),
                         note = CASE WHEN :n = '' THEN note ELSE :n END WHERE id = :id",
                 [':s' => $new, ':n' => $note, ':id' => (int)$r['id']]);
        $n++;
    }
    return $n;
}
