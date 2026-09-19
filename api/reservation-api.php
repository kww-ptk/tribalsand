<?php
declare(strict_types=1);
/**
 * Partner reservation API — the write half of the restaurant integration, so a
 * standalone restaurant site (e.g. the Zuri restaurant website) can take a table
 * booking on its own page and have it land in Admin → Reservations alongside the
 * ones tribalsand.com takes. Staff work ONE list; there is no second inbox.
 *
 *   POST /api/reservation-api.php                 → create a pending request
 *   GET  /api/reservation-api.php?reference=TSR-3-AB12C → look one up
 *
 * Auth: `Authorization: Bearer <RESTAURANT_API_KEY>` (or `?key=`). The key is
 * REQUIRED on both verbs and the endpoint reports itself disabled when no key is
 * configured — see includes/restaurant-api.php. This is a SERVER-TO-SERVER
 * integration: the partner's backend calls it. Never ship the key to a browser,
 * which is also why there are no CORS headers here.
 *
 * It is a request, not a confirmed booking: rows land `pending` exactly like the
 * website's own form, and staff confirm or cancel them in admin/reservations.php
 * (which sends the guest the confirmation mail). This endpoint deliberately
 * cannot set a status — a partner site must not be able to self-confirm.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/restaurant-api.php';
require_once __DIR__ . '/../includes/reservations.php';
require_once __DIR__ . '/../includes/mail.php';

restaurant_api_begin(false);            // server-to-server: no CORS
restaurant_api_authenticate(true);      // a key is mandatory for a writer

if (!reservations_supported()) {
    restaurant_api_json(['ok' => false, 'error' => 'Reservations are not available'], 503);
}

/** Public shape of a reservation row — never leaks the client IP or internal ids. */
function reservation_api_public(array $r): array {
    return [
        'reference'   => (string)($r['reference'] ?? ''),
        'status'      => (string)($r['status'] ?? 'pending'),
        'venue'       => ['slug' => (string)($r['venue_slug'] ?? ''), 'name' => (string)($r['venue_name'] ?? '')],
        'date'        => substr((string)($r['reservation_date'] ?? ''), 0, 10),
        'time'        => substr((string)($r['reservation_time'] ?? ''), 0, 5),
        'time_label'  => reservation_time_label((string)($r['reservation_time'] ?? '')),
        'party_size'  => (int)($r['party_size'] ?? 0),
        'guest_name'  => (string)($r['guest_name'] ?? ''),
        'notes'       => (string)($r['notes'] ?? ''),
        'source'      => (string)($r['source'] ?? ''),
        'created_at'  => !empty($r['created_at']) ? date('c', strtotime((string)$r['created_at'])) : null,
    ];
}


// ── GET: status lookup by reference ─────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $ref = trim((string)($_GET['reference'] ?? ''));
    if ($ref === '') {
        restaurant_api_json(['ok' => false, 'error' => 'Pass ?reference=<TSR-…>'], 400);
    }
    $row = fetch_reservation_by_reference($ref);
    if (!$row) restaurant_api_json(['ok' => false, 'error' => 'Reservation not found'], 404);
    restaurant_api_json(['ok' => true, 'reservation' => reservation_api_public($row)]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    restaurant_api_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

// ── POST: create ────────────────────────────────────────────────────────────
$in = restaurant_api_input();

// Resolve the venue by slug or id, published only.
$venue = false;
$slug  = trim((string)($in['venue'] ?? $in['venue_slug'] ?? ''));
$vid   = (int)($in['venue_id'] ?? 0);
try {
    if ($slug !== '') {
        $venue = db_query('SELECT id, slug, is_published FROM venues WHERE slug = :s', [':s' => $slug])->fetch();
    } elseif ($vid > 0) {
        $venue = db_query('SELECT id, slug, is_published FROM venues WHERE id = :id', [':id' => $vid])->fetch();
    }
} catch (Throwable $e) { $venue = false; }

// Rate limit by caller IP, same cap as the website form. A partner backend calls
// from one address, so give it a wider allowance than a single browser.
$ip = client_ip();
if (reservation_rate_limited($ip, 60)) {
    restaurant_api_json(['ok' => false, 'error' => 'Too many requests. Please slow down.'], 429);
}

$fields = [
    'reservation_date' => trim((string)($in['date'] ?? $in['reservation_date'] ?? '')),
    'reservation_time' => trim((string)($in['time'] ?? $in['reservation_time'] ?? '')),
    'party_size'       => (int)($in['party_size'] ?? $in['guests'] ?? 0),
    'guest_name'       => trim((string)($in['guest_name'] ?? $in['name'] ?? '')),
    'guest_phone'      => trim((string)($in['guest_phone'] ?? $in['phone'] ?? '')),
    'guest_email'      => trim((string)($in['guest_email'] ?? $in['email'] ?? '')),
    'notes'            => trim((string)($in['notes'] ?? $in['message'] ?? '')),
];

$errors = reservation_validate($fields, $venue);
if ($errors) {
    restaurant_api_json(['ok' => false, 'error' => 'Validation failed', 'errors' => $errors], 422);
}

// `source` labels where the booking came from so staff can see it in the admin
// list. Free text from the caller is slugged and capped — it is displayed.
$source = strtolower(trim((string)($in['source'] ?? 'partner')));
$source = preg_replace('/[^a-z0-9_\-]/', '', $source) ?: 'partner';
$source = substr($source, 0, 30);

try {
    $res = create_reservation([
        'venue_id'         => (int)$venue['id'],
        'menu_id'          => reservation_menu_id_for_venue((int)$venue['id']),
        'reservation_date' => date('Y-m-d', (int)strtotime($fields['reservation_date'])),
        'reservation_time' => $fields['reservation_time'],
        'party_size'       => $fields['party_size'],
        'guest_name'       => $fields['guest_name'],
        'guest_phone'      => $fields['guest_phone'],
        'guest_email'      => $fields['guest_email'],
        'notes'            => $fields['notes'],
        'source'           => $source,
    ]);
} catch (Throwable $e) {
    error_log('[reservation-api] create: ' . $e->getMessage());
    restaurant_api_json(['ok' => false, 'error' => 'Could not save the reservation'], 500);
}

// Same guest acknowledgement + staff alert the website form sends. A mail
// failure must not lose a booking that is already in the database.
try { send_reservation_received($res); }
catch (Throwable $e) { error_log('[reservation-api] mail: ' . $e->getMessage()); }

restaurant_api_json(['ok' => true, 'reservation' => reservation_api_public($res)], 201);
