<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/checkin.php';
require_once __DIR__ . '/../includes/staff-hold-guard.php'; // staff_hold_block_reason()
require_login();
require_bookings();

$checkin_default = checkin_supported() && setting('checkin_required_default', '0') === '1';
$want_checkin    = ($_SERVER['REQUEST_METHOD'] === 'POST') ? isset($_POST['require_checkin']) : $checkin_default;

$error = '';
$old   = ['unit_id' => '', 'check_in' => '', 'check_out' => '', 'guest_name' => '', 'guest_email' => '', 'guest_count' => 1];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    verify_csrf();
    $str      = fn($v) => is_scalar($v) ? trim((string)$v) : '';
    $unit_id  = (int)$str($_POST['unit_id'] ?? '');
    $check_in = $str($_POST['check_in']  ?? '');
    $check_out= $str($_POST['check_out'] ?? '');
    $g_name   = $str($_POST['guest_name']  ?? '');
    $g_email  = $str($_POST['guest_email'] ?? '');
    $g_count  = max(1, (int)($_POST['guest_count'] ?? 1));
    $old = ['unit_id' => $unit_id ?: '', 'check_in' => $check_in, 'check_out' => $check_out,
            'guest_name' => $g_name, 'guest_email' => $g_email, 'guest_count' => $g_count];

    $is_date  = fn($d) => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    // Property scope — a scoped account (reception) may only create a hold on a
    // unit belonging to one of its own venues, whatever it posts.
    $unitScope = venue_scope_sql('r.venue_id');
    $unit_ok  = $unit_id > 0 && db_query(
        "SELECT 1 FROM units u JOIN rooms r ON r.id = u.room_id
          WHERE u.id = :id AND u.is_active = TRUE" . ($unitScope !== '' ? " AND {$unitScope}" : ''),
        [':id' => $unit_id]
    )->fetchColumn();

    if (!$unit_ok)                                        $error = 'Please choose a valid room / unit.';
    elseif (!$is_date($check_in) || !$is_date($check_out)) $error = 'Please enter valid check-in and check-out dates.';
    elseif ($check_in >= $check_out)                     $error = 'Check-out must be after check-in.';
    elseif ($g_name === '')                              $error = 'Guest name is required.';
    elseif (!filter_var($g_email, FILTER_VALIDATE_EMAIL)) $error = 'A valid guest email is required.';

    // Oversell guard. This form deliberately does NOT check availability — staff
    // need to be able to record an overbooking — and that stays true everywhere
    // except a Maya Ilai villa, where the block written below claims the WHOLE
    // villa (components NULL) and would silently sell a bedroom a guest has
    // already bought. staff_hold_block_reason() returns null for every other
    // unit, so no other property's workflow changes.
    if (!$error) {
        $error = staff_hold_block_reason($unit_id, $check_in, $check_out) ?? '';
    }

    if (!$error) {
        try {
            // Pending, and no TTL: a booking staff typed in must not be expired by
            // the cron overnight the way an unattended web enquiry is.
            //
            // Maya Ilai: staff-entered bookings take the WHOLE villa (components
            // NULL). Safe — nothing can be oversold — but a per-bedroom admin
            // booking needs a component picker on this form first.
            //
            // The room is recorded on the hold explicitly. Staff pick a UNIT
            // here, so the room is the unit's own — identical to what the
            // holds -> units -> rooms join returned before, just no longer
            // left to be re-derived downstream.
            $room_id = (int) db_query('SELECT room_id FROM units WHERE id = :id',
                [':id' => $unit_id])->fetchColumn();
            $hold_id = create_hold_with_block($unit_id, null, $check_in, $check_out, $g_name, $g_email, 'pending', null, null, $room_id ?: null);
        } catch (Throwable $e) {
            error_log('[hold-new] create failed: ' . $e->getMessage());
            $error = 'Could not create the booking. Please try again.';
        }
        if (!$error) {
            if (checkin_supported() && $want_checkin) {
                db_query('UPDATE holds SET require_checkin = TRUE WHERE id = :id', [':id' => $hold_id]);
            }
            if (checkin_supported()) {
                db_query('UPDATE holds SET guest_count = :n WHERE id = :id', [':n' => max(1, (int)($_POST['guest_count'] ?? 1)), ':id' => $hold_id]);
            }
            $code = (string)db_query("SELECT access_code FROM holds WHERE id = :id", [':id' => $hold_id])->fetchColumn();
            try {
                audit_log('hold.create_manual', 'hold', $hold_id, "manual booking — {$g_name} {$check_in}→{$check_out}");
            } catch (Throwable $e) { error_log('[hold-new] audit failed: ' . $e->getMessage()); }
            $_SESSION['hold_flash'] = ['type' => 'success', 'msg' => "Booking #{$hold_id} created for {$g_name} — code {$code}."];
            header('Location: /admin/holds.php'); exit;
        }
    }
}

// The form lives inline on the Bookings list now (includes/hold-new-form.php).
// A refusal goes back there with the message and what was typed; a plain GET
// (old links, bookmarks) opens it.
if ($error !== '') {
    $_SESSION['hold_new_state'] = ['error' => $error, 'old' => $old, 'want_checkin' => $want_checkin];
}
header('Location: /admin/holds.php?new=1'); exit;
