<?php
/**
 * Who is this card, and what should the kiosk offer them?
 *
 * READ ONLY. Writes nothing, stores no photo, does not touch the device's
 * last-seen stamp. Authenticated by the DEVICE token exactly like
 * api/clock-punch.php — no one is signed in at a shared tablet — and for the
 * same reason it carries no CSRF token: there is no session to ride.
 *
 * Deliberately a separate endpoint from clock-punch.php rather than a mode on
 * it: a read and a write have different failure semantics and should not share
 * a door.
 *
 * No rate limit, deliberately: this needs a valid device token AND a valid
 * 128-bit card token, and anyone holding both can already write a punch — so it
 * exposes no surface clock-punch.php does not. The per-card limiter stays on
 * the write path.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/attendance-clock.php';

header('Content-Type: application/json');

function card_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    exit(json_encode(['ok' => false, 'error' => $msg]));
}

// Guards, in the same order as api/clock-punch.php.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') card_fail('Method not allowed', 405);
if (!attendance_punches_supported())       card_fail('Clocking in isn’t enabled yet.');
if (!clock_kiosk_enabled())                card_fail('Clocking in is switched off. Ask a manager.', 403);

$device = clock_device_by_token((string)($_POST['device_token'] ?? ''));
if (!$device) card_fail('This tablet is no longer registered. Ask a manager to set it up again.', 403);

$staff = clock_staff_by_token((string)($_POST['card'] ?? ''));
if (!$staff) card_fail('Card not recognised. See a manager.', 404);

$today = frontdesk_today_ymd();
$yest  = date('Y-m-d', strtotime('-1 day', strtotime($today)));

$state = clock_card_state(
    clock_day_row((int)$staff['id'], $today),
    clock_day_row((int)$staff['id'], $yest),
    clock_minutes_from_hms(date('H:i:s'))
);

$last = clock_display_time($state['last_min']);

// The sub-line under the greeting. Assembled here rather than in the browser so
// the wording lives beside the rule that produced it.
if ($state['blocked'] === 'status') {
    $message = 'Today is marked ' . attendance_status_label($state['status']) . '. See a manager.';
} elseif ($state['blocked'] === 'done') {
    $message = $last !== null
        ? 'You’re done for today — checked out at ' . $last . '.'
        : 'You’re done for today.';
} elseif ($state['stale']) {
    $message = 'Yesterday was left open — a manager will fix it.';
} elseif ($last === null) {
    $message = 'Ready to start your day?';
} else {
    $verb    = $state['last_kind'] === 'in' ? 'Checked in at ' : 'Checked out at ';
    $when    = $state['scope'] === 'yesterday' ? $last . ' yesterday' : $last;
    $message = $verb . $when;
}

echo json_encode([
    'ok'        => true,
    'error'     => null,
    'name'      => clock_first_name((string)$staff['full_name']),
    'full_name' => $staff['full_name'],
    'action'    => $state['action'],
    'last'      => $last,
    'last_kind' => $state['last_kind'],
    'scope'     => $state['scope'],
    'blocked'   => $state['blocked'],
    'stale'     => $state['stale'],
    'message'   => $message,
]);
