<?php
/**
 * Public restaurant reservation request → creates a `pending` row, emails the
 * guest an acknowledgement and alerts staff. Post-Redirect-Get: on success it
 * redirects back to /reserve.php?ok=<reference> (the success modal shows there);
 * on failure it flashes errors + old input to the session and redirects back.
 *
 * Turnstile (fail-closed) + IP rate limiting + CSRF guard the mutation.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';        // csrf + session
require_once __DIR__ . '/../includes/reservations.php';
require_once __DIR__ . '/../includes/mail.php';

session_init();

/** Flash errors + old input back to the form and stop. */
function _reserve_fail(array $errors, array $old, string $venueSlug = ''): void {
    $_SESSION['reserve_flash'] = ['errors' => $errors, 'old' => $old];
    $q = $venueSlug !== '' ? ('?venue=' . urlencode($venueSlug)) : '';
    header('Location: /reserve.php' . $q . '#reserve-form');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /reserve.php');
    exit;
}

verify_csrf();

// Honeypot — silently accept and redirect as if OK (don't tip off bots).
if (!empty($_POST['website'])) {
    header('Location: /reserve.php?ok=1');
    exit;
}

if (!reservations_supported()) {
    _reserve_fail(['general' => 'Reservations are not available right now. Please contact us directly.'], $_POST);
}

$old = [
    'venue_id'         => (int)($_POST['venue_id'] ?? 0),
    'reservation_date' => trim((string)($_POST['reservation_date'] ?? '')),
    'reservation_time' => trim((string)($_POST['reservation_time'] ?? '')),
    'party_size'       => (int)($_POST['party_size'] ?? 0),
    'guest_name'       => trim((string)($_POST['guest_name'] ?? '')),
    'guest_phone'      => trim((string)($_POST['guest_phone'] ?? '')),
    'guest_email'      => trim((string)($_POST['guest_email'] ?? '')),
    'notes'            => trim((string)($_POST['notes'] ?? '')),
];

// Resolve the venue up front so we can bounce the guest back to the right form.
$venue = $old['venue_id'] > 0
    ? db_query('SELECT id, slug, is_published FROM venues WHERE id = :id', [':id' => $old['venue_id']])->fetch()
    : false;
$venueSlug = $venue['slug'] ?? '';

// ── Turnstile (fail-closed) ──
$ip = client_ip();
if (!verify_captcha($_POST['cf-turnstile-response'] ?? '', $ip)) {
    _reserve_fail(['general' => 'Security check failed. Please try again.'], $old, $venueSlug);
}

// ── Rate limit: max 5 reservation requests per IP / 10 min ──
try {
    $recent = (int) db_query(
        "SELECT COUNT(*) FROM reservations WHERE client_ip = :ip AND created_at > now() - interval '10 minutes'",
        [':ip' => $ip]
    )->fetchColumn();
    if ($recent >= 5) {
        _reserve_fail(['general' => 'Too many requests. Please wait a few minutes and try again.'], $old, $venueSlug);
    }
} catch (Throwable $e) { /* never block on a rate-limit read failure */ }

// ── Validate ──
$errors = [];
if (!$venue || empty($venue['is_published'])) $errors['venue_id'] = 'Please choose a property.';

$slots = reservation_slots();
if ($old['reservation_time'] === '' || !isset($slots[$old['reservation_time']])) {
    $errors['reservation_time'] = 'Please choose a time.';
}

$dateTs = strtotime($old['reservation_date']);
if ($old['reservation_date'] === '' || $dateTs === false) {
    $errors['reservation_date'] = 'Please choose a date.';
} elseif (date('Y-m-d', $dateTs) < date('Y-m-d')) {
    $errors['reservation_date'] = 'Please choose today or a future date.';
}

if ($old['party_size'] < 1 || $old['party_size'] > 30) {
    $errors['party_size'] = 'Party size must be between 1 and 30.';
}
if ($old['guest_name'] === '')  $errors['guest_name']  = 'Your name is required.';
if ($old['guest_phone'] === '') $errors['guest_phone'] = 'A phone number is required.';
if ($old['guest_email'] !== '' && !filter_var($old['guest_email'], FILTER_VALIDATE_EMAIL)) {
    $errors['guest_email'] = 'Please enter a valid email, or leave it blank.';
}

if ($errors) {
    _reserve_fail($errors, $old, $venueSlug);
}

// ── Optional menu link (venue's first published menu, if any) ──
$menuId = null;
try {
    if (db_query("SELECT to_regclass('public.menus')")->fetchColumn()) {
        $menuId = db_query(
            "SELECT id FROM menus WHERE venue_id = :v AND is_published = TRUE ORDER BY sort_order, id LIMIT 1",
            [':v' => (int)$venue['id']]
        )->fetchColumn() ?: null;
    }
} catch (Throwable $e) { $menuId = null; }

// ── Create + notify ──
$res = create_reservation([
    'venue_id'         => (int)$venue['id'],
    'menu_id'          => $menuId,
    'reservation_date' => date('Y-m-d', $dateTs),
    'reservation_time' => $old['reservation_time'],
    'party_size'       => $old['party_size'],
    'guest_name'       => $old['guest_name'],
    'guest_phone'      => $old['guest_phone'],
    'guest_email'      => $old['guest_email'],
    'notes'            => $old['notes'],
    'source'           => 'web',
]);

try { send_reservation_received($res); }
catch (Throwable $e) { error_log('[reservation] mail: ' . $e->getMessage()); }

unset($_SESSION['reserve_flash']);
header('Location: /reserve.php?ok=' . urlencode((string)($res['reference'] ?? '1')) . ($venueSlug !== '' ? '&venue=' . urlencode($venueSlug) : ''));
exit;
