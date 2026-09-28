<?php
declare(strict_types=1);
/**
 * Activity log — a human-readable history for one lead (submission) or booking
 * (hold), read from the existing admin_audit_log. It answers "who did what, and
 * when": status changes, assignment/reassignment, confirms, cancels, replies,
 * check-in edits, billing and plan changes — each stamped with the admin and time.
 *
 * All the write sites already call audit_log() (includes/db.php) with
 * target_type 'submission' / 'hold' and target_id = the row id, so this is a
 * pure read layer. Every read is wrapped so a missing table never fatals a page.
 */
require_once __DIR__ . '/db.php';

/** Audit rows for one target, newest first. Empty on any error. */
function fetch_activity_log(string $type, int $id, int $limit = 60): array {
    if ($id <= 0) return [];
    $limit = max(1, min(200, $limit));
    try {
        return db_query(
            "SELECT l.*, a.name AS admin_name, a.email AS admin_email
             FROM admin_audit_log l
             LEFT JOIN admin_users a ON a.id = l.admin_id
             WHERE l.target_type = :t AND l.target_id = :i
             ORDER BY l.created_at DESC, l.id DESC
             LIMIT {$limit}",
            [':t' => $type, ':i' => $id]
        )->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** A friendly label for an audit action slug (falls back to a prettified slug). */
function activity_action_label(string $action): string {
    static $map = [
        'submission.assign'            => 'Lead assigned',
        'submission.status'            => 'Status changed',
        'hold.assign'                  => 'Booking assigned',
        'hold.confirm'                 => 'Booking confirmed',
        'hold.cancel'                  => 'Booking cancelled',
        'hold.create_from_submission'  => 'Converted enquiry to booking',
        'booking_message.admin_reply'  => 'Replied to guest',
        'checkin.require_toggle'       => 'Check-in requirement changed',
        'checkin.guest_count'          => 'Party size changed',
        'checkin.guest_fill'           => 'Guest details edited',
        'checkin.guest_upload'         => 'Passport scan uploaded',
        'checkin.guest_add'            => 'Guest added',
        'checkin.guest_remove'         => 'Guest removed',
        'portal.share_toggle'          => 'Portal sharing changed',
        'bill.add'                     => 'Bill item added',
        'bill.del'                     => 'Bill item removed',
        'bill.set_price'               => 'Bill price set',
        'itinerary.add'                => 'Plan item added',
        'itinerary.delete'             => 'Plan item removed',
        'hr_staff_create'              => 'Added to directory',
        'hr_staff_update'              => 'Directory details edited',
        'hr_staff_toggle'              => 'Status changed',
        'hr_staff_delete'              => 'Removed from directory',
        'hr.profile_save'              => 'Employment details edited',
        'attendance.save'              => 'Attendance recorded',
        'auth.logout'                  => 'Signed out',
        'pos.lock'                     => 'Locked the till',
        'pos.outlet_save'              => 'POS outlet edited',
        'pos.receipt_email'            => 'Emailed a receipt',
        'acct.payment'                 => 'Recorded a payment',
        'acct.invoice'                 => 'Issued an invoice',
        'acct.credit_note'             => 'Issued a credit note',
        'acct.refund'                  => 'Recorded a refund',
        'acct.deposit_applied'         => 'Applied a security deposit',
        'hold.decline'                 => 'Booking declined',
        'pos.terminal_register'        => 'Registered a till tablet',
        'pos.void'                     => 'Voided a sale',
        'pos.stock_receive'            => 'Received stock',
        'pos.stock_adjust'             => 'Counted stock',
        'review.save'                  => 'Edited a review',
        'review.delete'                => 'Deleted a review',
        'acct.ic_settlement'           => 'Recorded a settlement between companies',
        'company.create'               => 'Added a company',
        'company.update'               => 'Edited a company',
        'company.invoicing'            => 'Changed invoicing settings',
        'company.account_save'         => 'Edited a money account',
        'company.ownership'            => 'Changed what a company owns',
        'venue.update'                 => 'Edited a property',
        'staff_setpw'                  => 'Changed a password',
    ];
    if (isset($map[$action])) return $map[$action];
    // Prettify: "some.action_name" → "Action name"
    $s = str_replace(['.', '_'], ' ', $action);
    return ucfirst(trim($s));
}

/**
 * Render the activity-log timeline for a target. $type is 'submission' or 'hold'.
 * Self-contained (emits its own scoped CSS once per request). Safe when empty.
 */
function activity_log_html(string $type, int $id, bool $cssOnly = false): void {
    $rows = $cssOnly ? [] : fetch_activity_log($type, $id);
    if (!isset($GLOBALS['__actlog_css'])) {
        $GLOBALS['__actlog_css'] = true;
        echo '<style>
        .actlog{list-style:none;margin:0;padding:0}
        .actlog__item{display:flex;gap:10px;padding:10px 0;border-bottom:1px solid var(--border,#e7ded7)}
        .actlog__item:last-child{border-bottom:0}
        .actlog__dot{flex:none;width:8px;height:8px;border-radius:50%;background:#94a3b8;margin-top:6px}
        .actlog__body{flex:1;min-width:0}
        .actlog__act{font-weight:600;font-size:13px}
        .actlog__note{font-size:12.5px;color:var(--muted,#6b7280);margin-top:1px;word-break:break-word}
        .actlog__meta{font-size:11.5px;color:var(--muted,#9ca3af);margin-top:2px}
        .actlog__empty{color:var(--muted,#6b7280);font-size:13px;margin:0;padding:6px 0}
        </style>';
    }
    if ($cssOnly) return;
    if (!$rows) {
        echo '<p class="actlog__empty">No activity recorded yet.</p>';
        return;
    }
    echo '<ul class="actlog">';
    foreach ($rows as $r) {
        $who = trim((string)($r['admin_name'] ?? '')) ?: (string)($r['admin_email'] ?? '') ?: 'System';
        $when = date('j M Y · H:i', strtotime((string)$r['created_at']));
        echo '<li class="actlog__item"><span class="actlog__dot"></span><div class="actlog__body">';
        echo '<div class="actlog__act">' . e(activity_action_label((string)$r['action'])) . '</div>';
        if (!empty($r['notes'])) echo '<div class="actlog__note">' . e((string)$r['notes']) . '</div>';
        echo '<div class="actlog__meta">' . e($who) . ' · ' . e($when) . '</div>';
        echo '</div></li>';
    }
    echo '</ul>';
}

// ── One person's activity (Team → employee profile) ─────────────────────────
//
// Merges everything a person DID and everything done TO their record, newest first.
// Each source is its own table of record, so the history reaches back as far as
// the data does — not only from when this view was built:
//   · sign-ins            login_attempts (success) by the account's email / staff code
//   · till unlocks        login_attempts 'pos:<account id>' (success)
//   · sales + voids       pos_sales (admin_user_id / voided_by)
//   · clock in / out      attendance_punches (the kiosk)
//   · sign-out, till lock and every other admin action   admin_audit_log (admin_id)
//   · changes to the record by anyone                    admin_audit_log (target hr_staff)
// Nothing is logged twice: audit rows for pos.sale are skipped (pos_sales is the source).

const PERSON_ACTIVITY_KINDS = ['sale' => 'Sales', 'signin' => 'Sign-ins', 'clock' => 'Clock', 'admin' => 'Admin actions', 'record' => 'Changes to the record'];

/**
 * Activity rows for one team member: [['at' (timestamp string), 'kind', 'title',
 * 'note', 'who' (for changes by someone else)], …] newest first, at most $limit.
 * $acctId = their linked login account (0 = none).
 */
function fetch_person_activity(int $hrStaffId, int $acctId, int $limit = 100): array {
    $rows = [];
    $q = function (string $sql, array $a) { try { return db_query($sql, $a)->fetchAll(); } catch (Throwable $e) { return []; } };
    $lim = max(1, min(300, $limit));

    // Changes to the record (by whoever made them).
    foreach ($q("SELECT l.action, l.notes, l.created_at, a.name AS admin_name, a.email AS admin_email FROM admin_audit_log l
                  LEFT JOIN admin_users a ON a.id = l.admin_id
                 WHERE l.target_type = 'hr_staff' AND l.target_id = :i ORDER BY l.created_at DESC LIMIT {$lim}", [':i' => $hrStaffId]) as $r) {
        $rows[] = ['at' => $r['created_at'], 'kind' => 'record', 'title' => activity_action_label((string)$r['action']), 'note' => (string)$r['notes'],
                   'who' => trim((string)($r['admin_name'] ?? '')) ?: ((string)($r['admin_email'] ?? '') ?: 'System')];
    }
    // Clock in / out at the kiosk.
    foreach ($q("SELECT p.kind, p.punched_at, v.name AS venue FROM attendance_punches p LEFT JOIN venues v ON v.id = p.venue_id
                 WHERE p.hr_staff_id = :i ORDER BY p.punched_at DESC LIMIT {$lim}", [':i' => $hrStaffId]) as $r) {
        $rows[] = ['at' => $r['punched_at'], 'kind' => 'clock', 'title' => $r['kind'] === 'in' ? 'Clocked in' : 'Clocked out', 'note' => (string)($r['venue'] ?? ''), 'who' => ''];
    }

    if ($acctId > 0) {
        $acct = $q('SELECT email, access_code FROM admin_users WHERE id = :i', [':i' => $acctId])[0] ?? null;
        // Sign-ins to admin (email or staff code) and till unlocks (PIN).
        $keys = array_values(array_filter([(string)($acct['email'] ?? ''), (string)($acct['access_code'] ?? ''), 'pos:' . $acctId], fn($k) => $k !== ''));
        if ($keys) {
            $ph = []; $a = [];
            foreach ($keys as $n => $k) { $ph[] = ":k{$n}"; $a[":k{$n}"] = $k; }
            foreach ($q("SELECT email, ip_address, created_at FROM login_attempts WHERE success AND email IN (" . implode(',', $ph) . ") ORDER BY created_at DESC LIMIT {$lim}", $a) as $r) {
                $till = str_starts_with((string)$r['email'], 'pos:');
                $rows[] = ['at' => $r['created_at'], 'kind' => 'signin', 'title' => $till ? 'Unlocked the till (PIN)' : 'Signed in to admin', 'note' => '', 'who' => ''];
            }
        }
        // Sales rung up, and sales voided.
        foreach ($q("SELECT s.reference, s.total, s.currency, s.payment_method, s.status, s.created_at, o.name AS outlet FROM pos_sales s
                      JOIN pos_outlets o ON o.id = s.outlet_id WHERE s.admin_user_id = :u ORDER BY s.created_at DESC LIMIT {$lim}", [':u' => $acctId]) as $r) {
            $method = defined('POS_PAYMENT_METHODS') ? (POS_PAYMENT_METHODS[$r['payment_method']] ?? $r['payment_method'])
                    : (['cash' => 'Cash', 'card' => 'Card', 'room_charge' => 'Room charge', 'mobile_money' => 'M-Pesa', 'other' => 'Other'][$r['payment_method']] ?? ucfirst((string)$r['payment_method']));
            $rows[] = ['at' => $r['created_at'], 'kind' => 'sale', 'title' => 'Sale ' . $r['reference'] . ($r['status'] === 'voided' ? ' (later voided)' : ''),
                       'note' => $r['outlet'] . ' · ' . $r['currency'] . ' ' . number_format((float)$r['total'], 2) . ' · ' . $method, 'who' => ''];
        }
        foreach ($q("SELECT s.reference, s.void_reason, s.voided_at, o.name AS outlet FROM pos_sales s JOIN pos_outlets o ON o.id = s.outlet_id
                      WHERE s.voided_by = :u AND s.voided_at IS NOT NULL ORDER BY s.voided_at DESC LIMIT {$lim}", [':u' => $acctId]) as $r) {
            $rows[] = ['at' => $r['voided_at'], 'kind' => 'sale', 'title' => 'Voided sale ' . $r['reference'], 'note' => $r['outlet'] . ((string)$r['void_reason'] !== '' ? ' · ' . $r['void_reason'] : ''), 'who' => ''];
        }
        // Everything else they did in admin / at the till (sign-out, till lock, bookings, stock…).
        foreach ($q("SELECT action, notes, created_at FROM admin_audit_log WHERE admin_id = :u AND action NOT IN ('pos.sale')
                     AND NOT (target_type = 'hr_staff' AND target_id = :i) ORDER BY created_at DESC LIMIT {$lim}", [':u' => $acctId, ':i' => $hrStaffId]) as $r) {
            $kind = in_array($r['action'], ['auth.logout', 'pos.lock'], true) ? 'signin' : 'admin';
            $rows[] = ['at' => $r['created_at'], 'kind' => $kind, 'title' => activity_action_label((string)$r['action']), 'note' => (string)$r['notes'], 'who' => ''];
        }
    }
    usort($rows, fn($x, $y) => strtotime((string)$y['at']) <=> strtotime((string)$x['at']));
    return array_slice($rows, 0, $lim);
}

/** Render one person's activity with filter chips (All / Sales / Sign-ins / Clock / …). */
function person_activity_html(int $hrStaffId, int $acctId): void {
    $rows = fetch_person_activity($hrStaffId, $acctId);
    activity_log_html('', 0, true);   // emit the shared CSS once
    if (!$rows) {
        echo '<p class="actlog__empty">No activity recorded yet.' . ($acctId ? '' : ' Link a login account in Team to see their sign-ins and till sales here.') . '</p>';
        return;
    }
    $kinds = array_unique(array_column($rows, 'kind'));
    $uid = 'pact' . $hrStaffId;
    echo '<div class="pact__chips" data-pact="' . $uid . '"><button type="button" class="optchip is-on" data-kind="">All</button>';
    foreach (PERSON_ACTIVITY_KINDS as $k => $lbl) if (in_array($k, $kinds, true)) echo '<button type="button" class="optchip" data-kind="' . e($k) . '">' . e($lbl) . '</button>';
    echo '</div><ul class="actlog" id="' . $uid . '">';
    foreach ($rows as $r) {
        echo '<li class="actlog__item" data-kind="' . e($r['kind']) . '"><span class="actlog__dot actlog__dot--' . e($r['kind']) . '"></span><div class="actlog__body">';
        echo '<div class="actlog__act">' . e($r['title']) . '</div>';
        if ($r['note'] !== '') echo '<div class="actlog__note">' . e($r['note']) . '</div>';
        echo '<div class="actlog__meta">' . ($r['who'] !== '' ? e($r['who']) . ' · ' : '') . e(date('j M Y · H:i', strtotime((string)$r['at']))) . '</div>';
        echo '</div></li>';
    }
    echo '</ul>';
    echo '<p class="text-muted pact__note">Sign-outs and till locks are recorded from 28 Sep 2026; everything else goes back as far as the records do.' . ($acctId ? '' : ' Link a login account to see sign-ins and till sales.') . '</p>';
    if (empty($GLOBALS['__pact_js'])) {
        $GLOBALS['__pact_js'] = true;
        echo '<style>.pact__chips{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 10px}.pact__chips .optchip{cursor:pointer}.pact__chips .optchip.is-on{background:var(--brand);border-color:var(--brand);color:#fff}
              .actlog__dot--sale{background:#0f766e}.actlog__dot--signin{background:#1565c0}.actlog__dot--clock{background:#b45309}.actlog__dot--record{background:#94a3b8}.actlog__dot--admin{background:#6d28d9}
              .pact__note{font-size:11.5px;margin:10px 0 0}</style>';
        echo '<script>document.addEventListener("click",function(e){var b=e.target.closest("[data-pact] [data-kind]");if(!b)return;var w=b.closest("[data-pact]"),k=b.dataset.kind;
              w.querySelectorAll("[data-kind]").forEach(function(x){x.classList.toggle("is-on",x===b);});
              document.querySelectorAll("#"+w.dataset.pact+" [data-kind]").forEach(function(li){li.hidden=k!==""&&li.dataset.kind!==k;});});</script>';
    }
}
