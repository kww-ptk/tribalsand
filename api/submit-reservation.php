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

/**
 * Where to send the guest back to. Defaults to /reserve.php, but an embedded
 * form (e.g. a per-property restaurant landing page) may post a `redirect` to
 * keep the guest on its own page. Whitelisted to a same-site "/<slug>.php" file
 * that actually exists — never an off-site or path-traversal target (no open
 * redirect). Resolved once at request start.
 */
function _reserve_base(): string {
    static $base = null;
    if ($base !== null) return $base;
    $r = trim((string)($_POST['redirect'] ?? ''));
    if ($r !== '' && preg_match('~^/[a-z0-9\-]+\.php$~', $r) && is_file(__DIR__ . '/..' . $r)) {
        return $base = $r;
    }
    return $base = '/reserve.php';
}

/** Flash errors + old input back to the form and stop. */
function _reserve_fail(array $errors, array $old, string $venueSlug = ''): void {
    $_SESSION['reserve_flash'] = ['errors' => $errors, 'old' => $old];
    $q = $venueSlug !== '' ? ('?venue=' . urlencode($venueSlug)) : '';
    header('Location: ' . _reserve_base() . $q . '#reserve-form');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /reserve.php');
    exit;
}

verify_csrf();

// Honeypot — silently accept and redirect as if OK (don't tip off bots).
if (!empty($_POST['website'])) {
    header('Location: ' . _reserve_base() . '?ok=1');
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
if (reservation_rate_limited($ip)) {
    _reserve_fail(['general' => 'Too many requests. Please wait a few minutes and try again.'], $old, $venueSlug);
}

// ── Validate (the SAME validator the partner integration API uses) ──
$errors = reservation_validate($old, $venue);
if ($errors) {
    _reserve_fail($errors, $old, $venueSlug);
}
$dateTs = strtotime($old['reservation_date']);

// ── Optional menu link (venue's first published menu, if any) ──
$menuId = reservation_menu_id_for_venue((int)$venue['id']);

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
header('Location: ' . _reserve_base() . '?ok=' . urlencode((string)($res['reference'] ?? '1')) . ($venueSlug !== '' ? '&venue=' . urlencode($venueSlug) : ''));
exit;
