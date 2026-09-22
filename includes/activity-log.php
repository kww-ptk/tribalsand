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
function activity_log_html(string $type, int $id): void {
    $rows = fetch_activity_log($type, $id);
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
