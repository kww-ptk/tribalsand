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

/** Laundry service options offered on the concierge laundry form. */
const LAUNDRY_OPTIONS = [
    'wash_fold' => 'Wash & fold',
    'iron'      => 'Ironing',
    'dry_clean' => 'Dry-clean',
    'wash_iron' => 'Wash & iron',
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
                r.venue_id AS venue_id, v.name AS venue_name
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

/** Resolve a booking from a typed code alone (case-insensitive). */
function resolve_booking_by_code_only(string $code): array|false {
    $code = strtoupper(trim($code));
    if ($code === '') return false;
    $row = db_query(
        "SELECT h.*, u.name AS unit_name, r.name AS room_name, r.slug AS room_slug,
                r.venue_id AS venue_id, v.name AS venue_name
         FROM holds h
         JOIN units u  ON u.id = h.unit_id
         JOIN rooms r  ON r.id = u.room_id
         LEFT JOIN venues v ON v.id = r.venue_id
         WHERE h.access_code = :code",
        [':code' => $code]
    )->fetch();
    return $row ?: false;
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

/** Threads for a guest: the general thread + one per request, with latest message + unread-by-guest count. */
function fetch_message_threads(int $holdId): array {
    $threads = [['addon_id'=>null,'kind'=>'general','details'=>'','status'=>'','tour_name'=>null]];
    $addons = db_query(
        "SELECT ba.id AS addon_id, ba.kind, ba.details, ba.status, t.name AS tour_name
         FROM booking_addons ba LEFT JOIN tours t ON t.id = ba.tour_id
         WHERE ba.hold_id = :h ORDER BY ba.created_at DESC", [':h'=>$holdId]
    )->fetchAll();
    foreach ($addons as $a) { $threads[] = $a; }
    foreach ($threads as &$th) {
        $aid  = $th['addon_id'];
        $cond = $aid === null ? 'addon_id IS NULL' : 'addon_id = :aid';
        $p    = [':h'=>$holdId]; if ($aid !== null) $p[':aid'] = $aid;
        $last = db_query("SELECT body, sender, created_at FROM booking_messages WHERE hold_id=:h AND $cond ORDER BY created_at DESC LIMIT 1", $p)->fetch();
        $th['last_body']    = $last['body'] ?? '';
        $th['last_at']      = $last['created_at'] ?? null;
        $th['unread_guest'] = (int)db_query("SELECT COUNT(*) FROM booking_messages WHERE hold_id=:h AND $cond AND sender='admin' AND read_by_guest=FALSE", $p)->fetchColumn();
    }
    unset($th);
    return $threads;
}

/** All messages in one thread, oldest → newest. */
function fetch_thread_messages(int $holdId, ?int $addonId): array {
    $cond = $addonId === null ? 'addon_id IS NULL' : 'addon_id = :aid';
    $p    = [':h'=>$holdId]; if ($addonId !== null) $p[':aid'] = $addonId;
    return db_query("SELECT * FROM booking_messages WHERE hold_id=:h AND $cond ORDER BY created_at ASC", $p)->fetchAll();
}

/** Mark a thread's admin messages read by the guest. */
function mark_thread_read_by_guest(int $holdId, ?int $addonId): void {
    $cond = $addonId === null ? 'addon_id IS NULL' : 'addon_id = :aid';
    $p    = [':h'=>$holdId]; if ($addonId !== null) $p[':aid'] = $addonId;
    db_query("UPDATE booking_messages SET read_by_guest=TRUE WHERE hold_id=:h AND $cond AND sender='admin' AND read_by_guest=FALSE", $p);
}

/** Total admin messages unread by this guest (nav badge). */
function count_unread_guest(int $holdId): int {
    return (int)db_query("SELECT COUNT(*) FROM booking_messages WHERE hold_id=:h AND sender='admin' AND read_by_guest=FALSE", [':h'=>$holdId])->fetchColumn();
}

/** Admin: all threads across holds, unread-by-admin first, with guest/venue context. */
function fetch_admin_threads(): array {
    return db_query(
        "SELECT m.hold_id, m.addon_id,
                h.guest_name, v.name AS venue_name,
                ba.kind, ba.details, ba.status, t.name AS tour_name,
                MAX(m.created_at) AS last_at,
                SUM(CASE WHEN m.sender='guest' AND m.read_by_admin=FALSE THEN 1 ELSE 0 END) AS unread_admin,
                (SELECT body FROM booking_messages m2 WHERE m2.hold_id=m.hold_id AND ((m2.addon_id IS NULL AND m.addon_id IS NULL) OR m2.addon_id=m.addon_id) ORDER BY m2.created_at DESC LIMIT 1) AS last_body
         FROM booking_messages m
         JOIN holds h  ON h.id = m.hold_id
         JOIN units u  ON u.id = h.unit_id
         JOIN rooms r  ON r.id = u.room_id
         LEFT JOIN venues v ON v.id = r.venue_id
         LEFT JOIN booking_addons ba ON ba.id = m.addon_id
         LEFT JOIN tours t ON t.id = ba.tour_id
         GROUP BY m.hold_id, m.addon_id, h.guest_name, v.name, ba.kind, ba.details, ba.status, t.name
         ORDER BY unread_admin DESC, last_at DESC"
    )->fetchAll();
}

/** Admin: total guest messages unread by staff (nav badge). */
function count_unread_admin(): int {
    return (int)db_query("SELECT COUNT(*) FROM booking_messages WHERE sender='guest' AND read_by_admin=FALSE")->fetchColumn();
}

/** Mark a thread's guest messages read by admin. */
function mark_thread_read_by_admin(int $holdId, ?int $addonId): void {
    $cond = $addonId === null ? 'addon_id IS NULL' : 'addon_id = :aid';
    $p    = [':h'=>$holdId]; if ($addonId !== null) $p[':aid'] = $addonId;
    db_query("UPDATE booking_messages SET read_by_admin=TRUE WHERE hold_id=:h AND $cond AND sender='guest' AND read_by_admin=FALSE", $p);
}

/** Human title for a thread row (from fetch_message_threads / fetch_admin_threads). */
function thread_title(array $th): string {
    if (($th['addon_id'] ?? null) === null) return 'Message the team';
    $label = addon_label($th); // tour_name + details
    $kind  = ucfirst((string)($th['kind'] ?? 'Request'));
    return $label !== '' ? "{$kind} · {$label}" : $kind;
}

/** Change requests already recorded against a hold (for display). */
function fetch_booking_change_requests(int $holdId): array {
    return db_query(
        "SELECT * FROM booking_change_requests WHERE hold_id = :id ORDER BY created_at DESC",
        [':id' => $holdId]
    )->fetchAll();
}

/** Published tours with their hero image + fields, for the portal Activities page. */
function fetch_portal_activities(): array {
    return db_query(
        "SELECT t.id, t.slug, t.name, t.category, t.tag_label, t.duration, t.short_desc,
                (SELECT filename FROM tour_images ti WHERE ti.tour_id = t.id AND ti.is_hero = TRUE LIMIT 1) AS hero
         FROM tours t
         WHERE t.is_published = TRUE
         ORDER BY t.sort_order ASC, t.name ASC"
    )->fetchAll();
}

/**
 * Published guest-board posts visible to a guest at the given venue.
 * $venueId null (or a venue with no targeted posts) → only global posts (venue_id IS NULL).
 */
function fetch_guest_board(?int $venueId): array {
    return db_query(
        "SELECT id, category, title, body, image_filename
         FROM guest_board_posts
         WHERE is_published = TRUE
           AND (venue_id IS NULL OR venue_id = :venue)
         ORDER BY sort_order DESC, created_at DESC
         LIMIT 6",
        [':venue' => $venueId]
    )->fetchAll();
}

/**
 * Human label for an addon row (tour or concierge request), avoiding the
 * common case where a tour's details duplicate its name ("Tsavo East Tsavo East").
 * Expects a row with 'tour_name' (nullable) and 'details'.
 */
function addon_label(array $a): string {
    $name = trim((string)($a['tour_name'] ?? ''));
    $det  = trim((string)($a['details'] ?? ''));
    if ($name === '') return $det;
    if ($det === '' || $det === $name) return $name;
    return "{$name} — {$det}";
}

/** Friendly label for an addon request status (used in guest views and the admin Concierge Desk). */
function addon_status_label(string $status): string {
    return [
        'requested' => 'Requested',
        'confirmed' => 'In progress',
        'completed' => 'Done',
        'declined'  => 'Declined',
        'cancelled' => 'Cancelled',
    ][$status] ?? ucfirst($status);
}

/** Distinct published tour categories → {key,label} for the Activities filter. */
function fetch_tour_categories(): array {
    $labels = ['classic' => 'Classic safari', 'custom' => 'Custom journey', 'excursion' => 'Excursion'];
    $rows = db_query(
        "SELECT DISTINCT category FROM tours WHERE is_published = TRUE AND category <> '' ORDER BY category ASC"
    )->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($rows as $c) { $out[] = ['key' => $c, 'label' => $labels[$c] ?? ucfirst($c)]; }
    return $out;
}
