<?php
/**
 * Admin: restaurant reservation state changes (confirm / decline / cancel).
 *
 * Each UPDATE is guarded by the expected current status, so a concurrent click
 * cannot double-apply a transition or double-send the confirmation email.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/restaurant.php';
require_once __DIR__ . '/../includes/mail.php';
require_login();
require_frontdesk();
verify_csrf();

$back = '/admin/restaurant.php';
$fail = function (string $msg) use ($back): never {
    $_SESSION['restaurant_flash'] = ['type' => 'error', 'msg' => $msg];
    header('Location: ' . $back);
    exit;
};

$id     = (int)($_POST['id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$map = ['confirm' => 'confirmed', 'decline' => 'declined', 'cancel' => 'cancelled'];
if (!$id || ($action !== 'edit' && !isset($map[$action]))) $fail('Unknown action.');
$to = $action === 'edit' ? null : $map[$action];

$r = db_query(
    'SELECT r.*, v.name AS venue_name, v.slug AS venue_slug
       FROM restaurant_reservations r JOIN venues v ON v.id = r.venue_id
      WHERE r.id = :id',
    [':id' => $id]
)->fetch();
if (!$r) $fail('Reservation not found.');

// Venue scope — a scoped manager must not action another venue's covers.
$venueIds = admin_venue_ids();
if ($venueIds !== null && !in_array((int)$r['venue_id'], array_map('intval', $venueIds), true)) {
    $fail('That reservation belongs to another venue.');
}

// ── Edit: not a status transition. Allowed while the booking is still live. ──
// A declined/cancelled row is history and stays untouched, and this never
// emails the guest — the manager editing it is already talking to them.
if ($action === 'edit') {
    if (!in_array($r['status'], ['pending', 'confirmed'], true)) {
        $fail('A ' . $r['status'] . ' reservation can no longer be edited.');
    }

    // Array-valued POST fields must never reach a (string) cast — that emits
    // an "Array to string conversion" warning before the redirect headers,
    // and with display_errors on in production that corrupts the response
    // (same hazard the GET filters in restaurant.php already guard against).
    $postStr = static fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';

    $in = [
        'name'       => $r['guest_name'],   // unchanged, but restaurant_validate() requires them
        'email'      => $r['guest_email'],
        'party_size' => (int)($_POST['party_size'] ?? 0),
        'date'       => $postStr('date'),
        'time'       => $postStr('time'),
        'occasion'   => $postStr('occasion'),
        'notes'      => $postStr('notes'),
    ];

    // Staff may move a booking to any time the venue is open — but the date,
    // party bounds and notes cap are the same rules the public form obeys.
    // This is a second writer to the same rows the public endpoint writes, so
    // it goes through the same choke point rather than re-deriving the rules.
    $errors = restaurant_validate($in, restaurant_hours($r['venue_slug']), date('Y-m-d'));
    unset($errors['time']);   // an off-grid time is a deliberate staff override
    if ($errors) $fail(reset($errors));

    // Still enforce the SHAPE of the time (HH:MM, 00-23 / 00-59) even though
    // it may fall off the bookable grid — this is a TIME column, not free text.
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $in['time'])) $fail('Please enter a valid time.');

    // staff_notes has no field in restaurant_validate() — it is internal-only
    // and never reaches a guest email or the public site — so give it the
    // same null-byte and length guard by hand rather than skip it.
    $staffNotes = $postStr('staff_notes');
    if (str_contains($staffNotes, "\0")) $fail('Internal notes must be plain text.');
    if (mb_strlen($staffNotes) > 2000) $fail('Please keep internal notes under 2000 characters.');

    // Guarded like the transitions below: only succeeds while the row is still
    // pending/confirmed, closing the race where it flips to declined/cancelled
    // between the SELECT above and this UPDATE.
    $changed = db_query(
        "UPDATE restaurant_reservations
            SET reserved_on = :d, reserved_at = :t, party_size = :party,
                occasion = :occasion, notes = :notes, staff_notes = :staff, updated_at = NOW()
          WHERE id = :id AND status IN ('pending', 'confirmed')",
        [
            ':d'        => $in['date'],
            ':t'        => $in['time'],
            ':party'    => $in['party_size'],
            ':occasion' => $in['occasion'] !== '' ? $in['occasion'] : null,
            ':notes'    => $in['notes'] !== '' ? $in['notes'] : null,
            ':staff'    => $staffNotes !== '' ? $staffNotes : null,
            ':id'       => $id,
        ]
    )->rowCount();
    if ($changed === 0) $fail('Someone else just updated that reservation. Refresh and try again.');

    try {
        audit_log('restaurant_edit', 'restaurant_reservation', $id, $r['reference']);
    } catch (Throwable $e) {
        error_log('[restaurant-action] audit_log: ' . $e->getMessage());
    }

    $_SESSION['restaurant_flash'] = ['type' => 'success', 'msg' => 'Reservation ' . $r['reference'] . ' updated.'];
    header('Location: ' . $back);
    exit;
}

if (!restaurant_can_transition($r['status'], $to)) {
    $fail('A ' . $r['status'] . ' reservation cannot be marked ' . $to . '.');
}

// Guarded update: only succeeds if the row is still in the status we read.
$sql = $to === 'confirmed'
    ? 'UPDATE restaurant_reservations
          SET status = :to, confirmed_by = :admin, confirmed_at = NOW(), updated_at = NOW()
        WHERE id = :id AND status = :from'
    : 'UPDATE restaurant_reservations
          SET status = :to, updated_at = NOW()
        WHERE id = :id AND status = :from';

$args = [':to' => $to, ':id' => $id, ':from' => $r['status']];
if ($to === 'confirmed') $args[':admin'] = (int)($_SESSION['admin_id'] ?? 0);

$changed = db_query($sql, $args)->rowCount();
if ($changed === 0) $fail('Someone else just updated that reservation. Refresh and try again.');

// audit_log() must never be able to suppress the confirmation email below —
// give it its own try/catch, independent of the mail try/catch.
try {
    audit_log('restaurant_' . $action, 'restaurant_reservation', $id, $r['reference']);
} catch (Throwable $e) {
    error_log('[restaurant-action] audit_log: ' . $e->getMessage());
}

// Only the winning update emails the guest, and only on confirmation. A
// failed send must not roll back or error the response.
if ($to === 'confirmed') {
    try {
        send_restaurant_confirmed([
            'reference'   => $r['reference'],
            'guest_name'  => $r['guest_name'],
            'guest_email' => $r['guest_email'],
            'guest_phone' => $r['guest_phone'] ?? '',
            'party_size'  => $r['party_size'],
            'reserved_on' => $r['reserved_on'],
            'reserved_at' => substr($r['reserved_at'], 0, 5),
            'occasion'    => $r['occasion'] ?? '',
            'notes'       => $r['notes'] ?? '',
            'venue_name'  => $r['venue_name'],
            'venue_slug'  => $r['venue_slug'],
        ]);
    } catch (Throwable $e) {
        error_log('[restaurant-action] mail: ' . $e->getMessage());
    }
}

$_SESSION['restaurant_flash'] = ['type' => 'success', 'msg' => 'Reservation ' . $r['reference'] . ' marked ' . $to . '.'];
header('Location: ' . $back);
exit;
