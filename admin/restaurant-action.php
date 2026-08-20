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
$map    = ['confirm' => 'confirmed', 'decline' => 'declined', 'cancel' => 'cancelled'];
if (!$id || !isset($map[$action])) $fail('Unknown action.');
$to = $map[$action];

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
