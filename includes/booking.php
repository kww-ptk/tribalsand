<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Generate an HMAC-SHA256 token for a hold action (confirm or decline).
 * Used to create one-click action URLs in admin notification emails.
 * Token is NOT time-limited on its own — the hold's status is the gate.
 */
function make_hold_token(int $holdId, string $action): string {
    $secret = parse_env()['BOOKING_TOKEN_SECRET'] ?? '';
    if (!$secret) return '';
    return hash_hmac('sha256', "{$holdId}:{$action}", $secret);
}

/**
 * Verify a hold action token. Returns false if the secret is missing,
 * the token is empty, or the HMAC does not match.
 */
function verify_hold_token(int $holdId, string $action, string $token): bool {
    if (!$token) return false;
    $expected = make_hold_token($holdId, $action);
    if (!$expected) return false;
    return hash_equals($expected, $token);
}

/**
 * Generate a guest-facing booking reference (e.g. TS-42-a3f7c1b2).
 * The 8-char hex suffix is an HMAC so only the server can produce/verify it.
 * Returns '' if BOOKING_TOKEN_SECRET is not set (no self-service for guests).
 */
function make_guest_ref(int $holdId): string {
    $secret = parse_env()['BOOKING_TOKEN_SECRET'] ?? '';
    if (!$secret) return '';
    $hash = substr(hash_hmac('sha256', (string)$holdId, $secret), 0, 8);
    return "TS-{$holdId}-{$hash}";
}

/**
 * Verify a guest booking reference and return the hold ID, or false on failure.
 */
function verify_guest_ref(string $ref): int|false {
    $secret = parse_env()['BOOKING_TOKEN_SECRET'] ?? '';
    if (!$secret) return false;
    if (!preg_match('/^TS-(\d+)-([0-9a-f]{8})$/', $ref, $m)) return false;
    $holdId   = (int)$m[1];
    $expected = substr(hash_hmac('sha256', (string)$holdId, $secret), 0, 8);
    return hash_equals($expected, $m[2]) ? $holdId : false;
}

/** Transfer options offered on the manage page (no admin catalog — fixed list). */
const TRANSFER_OPTIONS = [
    'airport_to_property' => 'Airport → Property',
    'property_to_airport' => 'Property → Airport',
    'inter_property'      => 'Between properties',
    'custom'              => 'Custom transfer',
];

/** Absolute URL to the guest manage page for a hold (magic link). '' if secret unset. */
function make_manage_url(int $holdId): string {
    $ref = make_guest_ref($holdId);
    if (!$ref) return '';
    return site_url('/booking.php?ref=' . urlencode($ref));
}

/** Fetch a hold joined with unit/room/venue names for the guest view. */
function fetch_hold_for_guest(int $holdId): array|false {
    return db_query(
        "SELECT h.*, u.name AS unit_name, r.name AS room_name, r.slug AS room_slug,
                v.name AS venue_name
         FROM holds h
         JOIN units u  ON u.id = h.unit_id
         JOIN rooms r  ON r.id = u.room_id
         LEFT JOIN venues v ON v.id = r.venue_id
         WHERE h.id = :id",
        [':id' => $holdId]
    )->fetch();
}

/** Resolve a booking from a magic-link ref (TS-<id>-<hash>). */
function resolve_booking_by_ref(string $ref): array|false {
    $holdId = verify_guest_ref(trim($ref));
    if ($holdId === false) return false;
    return fetch_hold_for_guest($holdId);
}

/** Resolve a booking from a typed code + email (case-insensitive). */
function resolve_booking_by_code(string $code, string $email): array|false {
    $code  = strtoupper(trim($code));
    $email = strtolower(trim($email));
    if ($code === '' || $email === '') return false;
    $row = db_query(
        "SELECT h.*, u.name AS unit_name, r.name AS room_name, r.slug AS room_slug,
                v.name AS venue_name
         FROM holds h
         JOIN units u  ON u.id = h.unit_id
         JOIN rooms r  ON r.id = u.room_id
         LEFT JOIN venues v ON v.id = r.venue_id
         WHERE h.access_code = :code AND lower(h.guest_email) = :email",
        [':code' => $code, ':email' => $email]
    )->fetch();
    return $row ?: false;
}

/** Published tours grouped for the add-on catalog. */
function fetch_published_tours(): array {
    return db_query(
        "SELECT id, slug, name, category, tag_label, duration, short_desc
         FROM tours WHERE is_published = TRUE
         ORDER BY sort_order ASC, name ASC"
    )->fetchAll();
}

/** Add-ons already recorded against a hold (for display). */
function fetch_booking_addons(int $holdId): array {
    return db_query(
        "SELECT ba.*, t.name AS tour_name
         FROM booking_addons ba
         LEFT JOIN tours t ON t.id = ba.tour_id
         WHERE ba.hold_id = :id ORDER BY ba.created_at DESC",
        [':id' => $holdId]
    )->fetchAll();
}

/** Change requests already recorded against a hold (for display). */
function fetch_booking_change_requests(int $holdId): array {
    return db_query(
        "SELECT * FROM booking_change_requests WHERE hold_id = :id ORDER BY created_at DESC",
        [':id' => $holdId]
    )->fetchAll();
}
