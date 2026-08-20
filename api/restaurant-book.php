<?php
/**
 * Zuri restaurant — public table-request endpoint.
 *
 * Records the request as `pending` and notifies guest + staff. A human always
 * confirms; nothing here books a table. Capacity is deliberately NOT modelled.
 *
 * This is unauthenticated and internet-facing, so every response path must
 * stay pure JSON — no warning/notice text ahead of it, no uncaught throw
 * turning into an HTML fatal. See restaurant_validate() in
 * includes/restaurant.php for the shared field-level rules, and
 * restaurant_make_reference() for why the insert is wrapped in a try/catch.
 *
 * See docs/superpowers/specs/2026-08-19-restaurant-reservations-design.md
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/restaurant.php';
require_once __DIR__ . '/../includes/turnstile.php';
require_once __DIR__ . '/../includes/mail.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
}
if (!restaurant_supported()) {
    http_response_code(503);
    exit(json_encode(['ok' => false, 'error' => 'Bookings are not available right now.']));
}

// json_decode() can hand back null (bad JSON), or a bare scalar (the body was
// e.g. `"hi"` or `42` or `true`) as well as an array. Indexing a scalar with
// $data['x'] emits a warning ("Illegal string offset" / "Trying to access
// array offset on value of type ...") that would print ahead of our JSON, so
// pin $data to an array unconditionally before touching it.
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) $data = [];

// Scalar coercion mirroring restaurant_validate()'s internal $str() helper:
// reject non-scalars (arrays/bools/null) rather than let a raw (string) cast
// emit "Array to string conversion", or — under this file's strict_types —
// let a non-string reach a `string`-typed parameter and throw a TypeError.
$scalar = static fn($v): string => (is_string($v) || is_int($v) || is_float($v)) ? trim((string)$v) : '';

// Honeypot — accept silently so bots believe they succeeded.
if (!empty($data['website'])) {
    exit(json_encode(['ok' => true, 'reference' => 'ZR-00000']));
}

// Turnstile. verify_captcha() is fail-closed: site key set + secret missing = false.
if (!verify_captcha($scalar($data['cf-turnstile-response'] ?? ''))) {
    http_response_code(422);
    exit(json_encode(['ok' => false, 'error' => 'Please complete the anti-spam check and try again.']));
}

$ip = client_ip();

// Rate limit: 5 requests per IP per hour.
$since = date('Y-m-d H:i:s', time() - 3600);
$count = (int) db_query(
    'SELECT COUNT(*) AS cnt FROM restaurant_reservations WHERE ip_address = :ip AND created_at > :since',
    [':ip' => $ip, ':since' => $since]
)->fetch()['cnt'];
if ($count >= 5) {
    http_response_code(429);
    exit(json_encode(['ok' => false, 'error' => 'Too many requests. Please wait a few minutes.']));
}

$venueSlug = 'zuri';
$venue     = restaurant_venue($venueSlug);
if (!$venue) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'Restaurant unavailable.']));
}

// Validate against the venue's real hours — the client-side slot list is only a
// convenience, never the authority.
$cfg    = restaurant_hours($venueSlug);
$errors = restaurant_validate($data, $cfg, date('Y-m-d'));
if ($errors) {
    http_response_code(422);
    exit(json_encode(['ok' => false, 'errors' => $errors]));
}

// $errors === [] guarantees name/email/date/time/party_size/occasion/notes
// already passed restaurant_validate()'s scalar checks, so these casts are
// safe — but extract with the same $scalar() helper throughout anyway (not
// just where required) so this stays correct even if that contract changes.
$name     = $scalar($data['name']);
$email    = $scalar($data['email']);
$phone    = $scalar($data['phone'] ?? '');
$party    = (int)$data['party_size'];
$date     = $scalar($data['date']);
$time     = $scalar($data['time']);
$occasion = $scalar($data['occasion'] ?? '');
$notes    = $scalar($data['notes'] ?? '');

// Double-submit guard: the same guest asking for the same slot inside 5 minutes
// gets the existing reference back instead of a duplicate row.
$dupe = db_query(
    "SELECT reference FROM restaurant_reservations
      WHERE venue_id = :vid AND guest_email = :email
        AND reserved_on = :d AND reserved_at = :t
        AND created_at > NOW() - INTERVAL '5 minutes'
      LIMIT 1",
    [':vid' => (int)$venue['id'], ':email' => $email, ':d' => $date, ':t' => $time]
)->fetch();
if ($dupe) {
    exit(json_encode(['ok' => true, 'reference' => $dupe['reference'], 'duplicate' => true]));
}

if (session_status() === PHP_SESSION_NONE) session_start();
$tracking = $_SESSION['tracking'] ?? [];

// Everything from here on is wrapped: restaurant_make_reference() calls
// random_int(), which can throw \Random\RandomException if the system CSPRNG
// is unavailable, and that call sits outside the PDO retry loop's own
// try/catch below. An uncaught throw here would produce an HTML fatal and
// break the JSON response contract, so catch anything unexpected and answer
// with a clean JSON 500 instead. The booking itself only "succeeds" once the
// INSERT below commits — nothing before this block has written anything.
try {
    // Insert, retrying on the (vanishingly rare) reference collision.
    $reference = '';
    $id        = 0;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $reference = restaurant_make_reference();
        try {
            db_query(
                "INSERT INTO restaurant_reservations
                    (reference, venue_id, status, guest_name, guest_email, guest_phone,
                     party_size, reserved_on, reserved_at, occasion, notes,
                     source_page, referrer, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                     user_agent, ip_address)
                 VALUES
                    (:ref, :vid, 'pending', :name, :email, :phone,
                     :party, :d, :t, :occasion, :notes,
                     :source_page, :referrer, :utm_source, :utm_medium, :utm_campaign, :utm_term, :utm_content,
                     :user_agent, :ip)",
                [
                    ':ref'         => $reference,
                    ':vid'         => (int)$venue['id'],
                    ':name'        => $name,
                    ':email'       => $email,
                    ':phone'       => $phone,
                    ':party'       => $party,
                    ':d'           => $date,
                    ':t'           => $time,
                    ':occasion'    => $occasion !== '' ? $occasion : null,
                    ':notes'       => $notes !== '' ? $notes : null,
                    ':source_page' => $tracking['source_page'] ?? '',
                    ':referrer'    => $tracking['referrer']    ?? '',
                    ':utm_source'  => $tracking['utm_source']  ?? '',
                    ':utm_medium'  => $tracking['utm_medium']  ?? '',
                    ':utm_campaign'=> $tracking['utm_campaign']?? '',
                    ':utm_term'    => $tracking['utm_term']    ?? '',
                    ':utm_content' => $tracking['utm_content'] ?? '',
                    ':user_agent'  => $tracking['user_agent']  ?? '',
                    ':ip'          => $ip,
                ]
            );
            $id = (int) db()->lastInsertId();
            break;
        } catch (PDOException $e) {
            // 23505 = unique_violation. Only a reference clash is retryable.
            if ($e->getCode() !== '23505' || $attempt === 4) throw $e;
        }
    }

    audit_log('restaurant_request', 'restaurant_reservation', $id, $reference);

    // Email is best-effort. The booking is already committed — a dead Resend key
    // must cost a notification, never a reservation.
    try {
        send_restaurant_request([
            'reference'   => $reference,
            'guest_name'  => $name,
            'guest_email' => $email,
            'guest_phone' => $phone,
            'party_size'  => $party,
            'reserved_on' => $date,
            'reserved_at' => $time,
            'occasion'    => $occasion,
            'notes'       => $notes,
            'venue_name'  => $venue['name'],
            'venue_slug'  => $venue['slug'],
        ]);
    } catch (Throwable $e) {
        error_log('[restaurant-book] mail: ' . $e->getMessage());
    }

    echo json_encode(['ok' => true, 'id' => $id, 'reference' => $reference]);
} catch (Throwable $e) {
    error_log('[restaurant-book] fatal: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Something went wrong on our end. Please try again shortly.']);
}
