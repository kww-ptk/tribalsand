<?php
/**
 * Zuri restaurant — public table-request endpoint.
 *
 * Records the request as `pending` and notifies guest + staff. A human always
 * confirms; nothing here books a table. Capacity is deliberately NOT modelled.
 *
 * This is unauthenticated and internet-facing, so every response path must
 * stay pure JSON — no warning/notice text ahead of it, no uncaught throw
 * turning into an HTML fatal, no leaked SQL/paths/stack trace from a DB
 * blip. See restaurant_validate() in includes/restaurant.php for the shared
 * field-level rules, and restaurant_make_reference() for why the insert is
 * wrapped in a try/catch.
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
// Catches Throwable itself — safe to call before the try block below.
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

// Everything from here on — including the rate-limit COUNT, restaurant_venue(),
// restaurant_hours() (which reads `settings` via setting()), the dupe-guard
// SELECT/UPDATE and session_start() — touches the DB or otherwise can throw.
// A statement timeout, pooler hiccup or partially-applied migration on any of
// it would otherwise produce an HTML fatal to an anonymous caller: SQL text,
// the caller's IP, absolute filesystem paths and a stack trace, all under a
// Content-Type of application/json. One try/catch(Throwable) around the whole
// request body answers with a clean JSON 500 instead. The early `exit()`
// calls below are unaffected by sitting inside a try block — exit() is not a
// throw, so the catch never fires for the normal early-return paths.
try {
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

    // $errors === [] guarantees name/email/phone/date/time/party_size/occasion/
    // notes already passed restaurant_validate()'s scalar checks, so these casts
    // are safe — but extract with the same $scalar() helper throughout anyway
    // (not just where required) so this stays correct even if that contract
    // changes.
    $name     = $scalar($data['name']);
    $email    = $scalar($data['email']);
    $phone    = $scalar($data['phone'] ?? '');
    $party    = (int)$data['party_size'];
    $date     = $scalar($data['date']);
    $time     = $scalar($data['time']);
    $occasion = $scalar($data['occasion'] ?? '');
    $notes    = $scalar($data['notes'] ?? '');

    // Double-submit guard: the same guest asking for the same slot inside 5
    // minutes gets the existing reference back instead of a duplicate row.
    // This can also be a genuine correction (wrong party size, added an
    // occasion/notes, fixed a typo'd name/phone) rather than an accidental
    // double-click — bring the row up to date either way, but only re-alert
    // staff when something they'd act on actually changed.
    $dupe = db_query(
        "SELECT id, reference, guest_name, guest_phone, party_size, occasion, notes
           FROM restaurant_reservations
          WHERE venue_id = :vid AND guest_email = :email
            AND reserved_on = :d AND reserved_at = :t
            AND created_at > NOW() - INTERVAL '5 minutes'
          LIMIT 1",
        [':vid' => (int)$venue['id'], ':email' => $email, ':d' => $date, ':t' => $time]
    )->fetch();
    if ($dupe) {
        $oldOccasion = (string)($dupe['occasion'] ?? '');
        $oldNotes    = (string)($dupe['notes']    ?? '');
        $changed = (string)$dupe['guest_name']  !== $name
                || (string)$dupe['guest_phone'] !== $phone
                || (int)$dupe['party_size']     !== $party
                || $oldOccasion !== $occasion
                || $oldNotes    !== $notes;

        // Always write the resubmitted values — there is no trigger on this
        // table, so updated_at only reflects reality if we set it ourselves.
        db_query(
            "UPDATE restaurant_reservations
                SET guest_name = :name, guest_phone = :phone, party_size = :party,
                    occasion = :occasion, notes = :notes, updated_at = NOW()
              WHERE id = :id",
            [
                ':name'     => $name,
                ':phone'    => $phone,
                ':party'    => $party,
                ':occasion' => $occasion !== '' ? $occasion : null,
                ':notes'    => $notes !== '' ? $notes : null,
                ':id'       => (int)$dupe['id'],
            ]
        );

        if ($changed) {
            // Only the staff half — the guest doesn't need a second "we've
            // received your request" email for correcting their own request.
            try {
                send_restaurant_staff_alert([
                    'reference'   => $dupe['reference'],
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
                error_log('[restaurant-book] mail (correction): ' . $e->getMessage());
            }
        }

        exit(json_encode(['ok' => true, 'reference' => $dupe['reference'], 'duplicate' => true]));
    }

    if (session_status() === PHP_SESSION_NONE) session_start();
    $tracking = $_SESSION['tracking'] ?? [];

    // Insert, retrying on the (vanishingly rare) reference collision.
    // restaurant_make_reference() calls random_int(), which can throw
    // \Random\RandomException if the system CSPRNG is unavailable — that call
    // sits outside the PDO retry loop's own try/catch below, but is still
    // covered by the outer try/catch(Throwable) around this whole file.
    $reference = '';
    $id        = 0;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $reference = restaurant_make_reference();
        try {
            // RETURNING id instead of a separate lastInsertId() round-trip:
            // PDO_PGSQL implements lastInsertId() as SELECT lastval(), and
            // this DB (Neon) pools connections under transaction pooling,
            // where that follow-up statement can land on a different backend
            // than the INSERT and return an error or another table's
            // sequence value. RETURNING ties the id to this exact statement.
            $id = (int) db_query(
                "INSERT INTO restaurant_reservations
                    (reference, venue_id, status, guest_name, guest_email, guest_phone,
                     party_size, reserved_on, reserved_at, occasion, notes,
                     source_page, referrer, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                     user_agent, ip_address)
                 VALUES
                    (:ref, :vid, 'pending', :name, :email, :phone,
                     :party, :d, :t, :occasion, :notes,
                     :source_page, :referrer, :utm_source, :utm_medium, :utm_campaign, :utm_term, :utm_content,
                     :user_agent, :ip)
                 RETURNING id",
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
            )->fetchColumn();
            break;
        } catch (PDOException $e) {
            // 23505 = unique_violation. Only a reference clash is retryable.
            if ($e->getCode() !== '23505' || $attempt === 4) throw $e;
        }
    }

    // The booking is already committed at this point. audit_log() and the
    // email below are both non-essential side effects and must never be able
    // to suppress each other or lose the reservation's only proof-of-life:
    // the premise of this whole feature is "a human always confirms", so
    // losing the staff alert is the expensive failure to avoid. Each gets
    // its own try/catch rather than sharing one, so a broken audit log can
    // never take the notification down with it.
    try {
        audit_log('restaurant_request', 'restaurant_reservation', $id, $reference);
    } catch (Throwable $e) {
        error_log('[restaurant-book] audit_log: ' . $e->getMessage());
    }

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
