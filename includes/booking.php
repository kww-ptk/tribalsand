<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/services.php';
require_once __DIR__ . '/team.php';

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

/** Absolute URL to the guest manage page for a hold (magic link). '' if secret unset. */
function make_manage_url(int $holdId): string {
    $ref = make_guest_ref($holdId);
    if (!$ref) return '';
    return site_url('/booking.php?ref=' . urlencode($ref));
}

/**
 * Per-guest self-service token (value of ?g=): "<guestId>-<hmac10>". Binds hold+guest so
 * a co-guest can complete only their own passport/waiver without the hold magic link.
 * The "g:" prefix domain-separates it from the hold ref (which hashes just the id).
 */
function make_guest_pass_token(int $holdId, int $guestId): string {
    $secret = parse_env()['BOOKING_TOKEN_SECRET'] ?? '';
    if (!$secret) return '';
    $hash = substr(hash_hmac('sha256', "g:{$holdId}:{$guestId}", $secret), 0, 10);
    return "{$guestId}-{$hash}";
}

/**
 * Verify a ?g= token. Returns [holdId, guestId] or false. Resolves hold_id from the guest
 * row, so a removed/re-added guest's old link stops working automatically.
 */
function verify_guest_pass_token(string $tok): array|false {
    $secret = parse_env()['BOOKING_TOKEN_SECRET'] ?? '';
    if (!$secret) return false;
    if (!preg_match('/^(\d+)-([0-9a-f]{10})$/', $tok, $m)) return false;
    $guestId = (int)$m[1];
    try { $row = db_query('SELECT hold_id FROM checkin_guests WHERE id = :g', [':g' => $guestId])->fetch(); }
    catch (Throwable $e) { return false; }
    if (!$row) return false;
    $holdId   = (int)$row['hold_id'];
    $expected = substr(hash_hmac('sha256', "g:{$holdId}:{$guestId}", $secret), 0, 10);
    return hash_equals($expected, $m[2]) ? [$holdId, $guestId] : false;
}

/** Absolute co-guest self-service URL (the lead copies this to send). '' if secret unset. */
function make_guest_pass_url(int $holdId, int $guestId): string {
    $tok = make_guest_pass_token($holdId, $guestId);
    return $tok === '' ? '' : site_url('/booking.php?g=' . urlencode($tok));
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

/** True once add_shared_portal.sql is applied (holds.share_reservation). Cached. */
function share_reservation_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db_query('SELECT share_reservation FROM holds LIMIT 1'); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** True if this hold has sharing turned on (and the column exists). */
function share_reservation_on(array $hold): bool {
    return share_reservation_supported() && !empty($hold['share_reservation']);
}

/** True once booking_addons.requested_by exists. Cached. */
function addon_requested_by_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db_query('SELECT requested_by FROM booking_addons LIMIT 1'); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** First name for attribution display, else "Guest". Pure. */
function guest_display_name(?array $g): string {
    $n = trim((string)($g['passport_name'] ?? ''));
    return $n === '' ? 'Guest' : explode(' ', $n)[0];
}

/**
 * Resolve a portal token (from ?ref= / ?g= / a posted field) to the acting party.
 * Returns ['hold'=>row, 'guest_id'=>int, 'is_lead'=>bool] or false.
 */
function resolve_portal_actor(string $token): array|false {
    $token = trim($token);
    if (($holdId = verify_guest_ref($token)) !== false) {
        $hold = fetch_hold_for_guest($holdId);
        if (!$hold) return false;
        return ['hold' => $hold, 'guest_id' => checkin_ensure_lead_guest_id($holdId), 'is_lead' => true];
    }
    if (($r = verify_guest_pass_token($token)) !== false) {
        [$holdId, $guestId] = $r;
        $hold = fetch_hold_for_guest($holdId);
        if (!$hold || !share_reservation_on($hold)) return false;
        return ['hold' => $hold, 'guest_id' => $guestId, 'is_lead' => false];
    }
    return false;
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

/** Map a booking_addons kind to an itinerary category. */
function _itin_map_kind(string $kind): string {
    if ($kind === 'tour' || $kind === 'transfer') return $kind;
    return in_array($kind, ['laundry','housekeeping','amenities','maintenance','restaurant','event'], true) ? 'activity' : 'note';
}

/**
 * Day-by-day itinerary for a hold: check-in → check-out, merging auto anchors,
 * confirmed scheduled requests, and admin itinerary_items. Guarded so the Stay
 * tab still renders (anchors only) if the table is absent pre-migration.
 */
function fetch_itinerary(array $hold): array {
    $start = new DateTime((string)$hold['check_in']);
    $end   = new DateTime((string)$hold['check_out']);
    $today = date('Y-m-d');
    $buckets = []; $order = [];
    for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
        $k = $d->format('Y-m-d'); $buckets[$k] = []; $order[] = $k;
    }
    $ciKey = $start->format('Y-m-d'); $coKey = $end->format('Y-m-d');
    $buckets[$ciKey][] = ['id'=>null,'sort'=>0,   'time'=>null,'category'=>'checkin', 'title'=>'Check-in', 'detail'=>(string)($hold['room_name'] ?? ''),'source'=>'auto'];
    $buckets[$coKey][] = ['id'=>null,'sort'=>2000,'time'=>null,'category'=>'checkout','title'=>'Check-out','detail'=>'','source'=>'auto'];

    try {
        $reqs = db_query(
            "SELECT ba.*, t.name AS tour_name FROM booking_addons ba LEFT JOIN tours t ON t.id = ba.tour_id
             WHERE ba.hold_id = :h AND ba.scheduled_for IS NOT NULL AND ba.status IN ('confirmed','completed')",
            [':h'=>(int)$hold['id']]
        )->fetchAll();
        foreach ($reqs as $r) {
            $ts = strtotime((string)$r['scheduled_for']); if ($ts === false) continue;
            $k = date('Y-m-d', $ts); if (!isset($buckets[$k])) continue;
            $min = (int)date('G',$ts)*60 + (int)date('i',$ts);
            $buckets[$k][] = ['id'=>null,'sort'=>100+$min,'time'=>date('H:i',$ts),'category'=>_itin_map_kind((string)$r['kind']),'title'=>addon_label($r),'detail'=>'from your request','source'=>'request'];
        }
        $items = db_query("SELECT * FROM itinerary_items WHERE hold_id = :h ORDER BY at_time NULLS LAST, sort_order, id", [':h'=>(int)$hold['id']])->fetchAll();
        foreach ($items as $it) {
            $k = (string)$it['day']; if (!isset($buckets[$k])) continue;
            if (!empty($it['at_time'])) {
                $ts = strtotime((string)$it['at_time']); $min = (int)date('G',$ts)*60 + (int)date('i',$ts);
                $sort = 100 + $min; $time = date('H:i', $ts);
            } else { $sort = 1500 + (int)$it['sort_order']; $time = null; }
            $src = (($it['created_by'] ?? 'admin') === 'guest') ? 'guest' : 'admin';
            $buckets[$k][] = ['id'=>(int)$it['id'],'sort'=>$sort,'time'=>$time,'category'=>(string)$it['category'],'title'=>(string)$it['title'],'detail'=>(string)($it['detail'] ?? ''),'source'=>$src];
        }
    } catch (Throwable $e) { /* tables absent pre-migration — anchors still render */ }

    $out = []; $n = 0;
    foreach ($order as $k) {
        $n++;
        usort($buckets[$k], fn($a,$b) => $a['sort'] <=> $b['sort']);
        $out[] = ['date'=>$k,'label'=>'Day '.$n.' · '.(new DateTime($k))->format('D j M'),'is_today'=>($k===$today),'items'=>$buckets[$k]];
    }
    return $out;
}

/** Admin: raw itinerary rows for a hold. */
function fetch_itinerary_items(int $holdId): array {
    return db_query("SELECT * FROM itinerary_items WHERE hold_id = :h ORDER BY day, at_time NULLS LAST, sort_order", [':h'=>$holdId])->fetchAll();
}

/** Threads for a guest: the general thread + one per request, with latest message + unread-by-guest count. */
function fetch_message_threads(int $holdId): array {
    $threads = [['addon_id'=>null,'kind'=>'general','details'=>'','status'=>'','tour_name'=>null]];
    $addons = db_query(
        "SELECT ba.id AS addon_id, ba.kind, ba.details, ba.status, ba.created_at, t.name AS tour_name
         FROM booking_addons ba LEFT JOIN tours t ON t.id = ba.tour_id
         WHERE ba.hold_id = :h ORDER BY ba.created_at DESC", [':h'=>$holdId]
    )->fetchAll();
    foreach ($addons as $a) { $threads[] = $a; }
    foreach ($threads as &$th) {
        $aid  = $th['addon_id'];
        $cond = $aid === null ? 'addon_id IS NULL' : 'addon_id = :aid';
        $p    = [':h'=>$holdId]; if ($aid !== null) $p[':aid'] = $aid;
        try {
            $last = db_query("SELECT body, sender, created_at FROM booking_messages WHERE hold_id=:h AND $cond ORDER BY created_at DESC LIMIT 1", $p)->fetch();
            $th['unread_guest'] = (int)db_query("SELECT COUNT(*) FROM booking_messages WHERE hold_id=:h AND $cond AND sender='admin' AND read_by_guest=FALSE", $p)->fetchColumn();
            $th['unread_admin'] = (int)db_query("SELECT COUNT(*) FROM booking_messages WHERE hold_id=:h AND $cond AND sender='guest' AND read_by_admin=FALSE", $p)->fetchColumn();
        } catch (Throwable $e) { $last = false; $th['unread_guest'] = 0; $th['unread_admin'] = 0; }  // table absent pre-migration
        $th['last_body']    = $last['body'] ?? '';
        $th['last_at']      = $last['created_at'] ?? null;
    }
    unset($th);

    // General thread stays pinned; request threads sort by most-recent activity
    // (last message, falling back to the request's creation time).
    $general = array_shift($threads);
    usort($threads, function ($a, $b) {
        $ka = (string)($a['last_at'] ?? $a['created_at'] ?? '');
        $kb = (string)($b['last_at'] ?? $b['created_at'] ?? '');
        return strcmp($kb, $ka); // most recent first
    });
    array_unshift($threads, $general);
    return $threads;
}

/** All messages in one thread, oldest → newest. Empty if the table is absent (pre-migration). */
function fetch_thread_messages(int $holdId, ?int $addonId): array {
    $cond = $addonId === null ? 'addon_id IS NULL' : 'addon_id = :aid';
    $p    = [':h'=>$holdId]; if ($addonId !== null) $p[':aid'] = $addonId;
    try {
        return db_query("SELECT * FROM booking_messages WHERE hold_id=:h AND $cond ORDER BY created_at ASC", $p)->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** New messages in a thread with id greater than $afterId, oldest → newest. Powers live polling. */
function fetch_thread_messages_since(int $holdId, ?int $addonId, int $afterId): array {
    $cond = $addonId === null ? 'addon_id IS NULL' : 'addon_id = :aid';
    $p    = [':h'=>$holdId, ':after'=>$afterId]; if ($addonId !== null) $p[':aid'] = $addonId;
    try {
        return db_query("SELECT * FROM booking_messages WHERE hold_id=:h AND $cond AND id > :after ORDER BY id ASC", $p)->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** Consistent "j M, H:i" timestamp used by both the initial render and the live poll. */
function message_time_label($createdAt): string {
    return date('j M, H:i', strtotime((string)$createdAt));
}

/** Shape a booking_messages row for a JSON poll/send response (id, sender, body, time_label). */
function message_payload(array $m): array {
    return [
        'id'         => (int)$m['id'],
        'sender'     => (string)$m['sender'],
        'body'       => (string)$m['body'],
        'time_label' => message_time_label($m['created_at'] ?? 'now'),
    ];
}

/** Mark a thread's admin messages read by the guest. No-op if the table is absent. */
function mark_thread_read_by_guest(int $holdId, ?int $addonId): void {
    $cond = $addonId === null ? 'addon_id IS NULL' : 'addon_id = :aid';
    $p    = [':h'=>$holdId]; if ($addonId !== null) $p[':aid'] = $addonId;
    try {
        db_query("UPDATE booking_messages SET read_by_guest=TRUE WHERE hold_id=:h AND $cond AND sender='admin' AND read_by_guest=FALSE", $p);
    } catch (Throwable $e) { /* table absent pre-migration */ }
}

/** Total admin messages unread by this guest (nav badge). Returns 0 if the table is absent (pre-migration). */
function count_unread_guest(int $holdId): int {
    try {
        return (int)db_query("SELECT COUNT(*) FROM booking_messages WHERE hold_id=:h AND sender='admin' AND read_by_guest=FALSE", [':h'=>$holdId])->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/** Admin: all threads across holds, unread-by-admin first, with guest/venue context. Pass venue ids to scope to staff-assigned venues. */
function fetch_admin_threads(?array $venueIds = null): array {
    $venueSql = '';
    $params = [];
    if ($venueIds !== null) {           // null = owner (all venues); array = staff scope
        if (!$venueIds) {
            $venueSql = ' AND 1=0';     // staff with no assigned venue sees nothing
        } else {
            $names = [];
            foreach (array_values($venueIds) as $i => $v) { $n = ":v$i"; $names[] = $n; $params[$n] = (int)$v; }
            $venueSql = ' AND r.venue_id IN (' . implode(',', $names) . ')';
        }
    }
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
         WHERE 1=1{$venueSql}
         GROUP BY m.hold_id, m.addon_id, h.guest_name, v.name, ba.kind, ba.details, ba.status, t.name
         ORDER BY unread_admin DESC, last_at DESC",
        $params
    )->fetchAll();
}

/** Admin: total guest messages unread by staff (nav badge). Pass venue ids to scope to staff-assigned venues. Returns 0 if the table is absent (pre-migration) so the admin layout never fatals. */
function count_unread_admin(?array $venueIds = null): int {
    try {
        if ($venueIds !== null) {        // null = owner (all); array = staff scope
            if (!$venueIds) return 0;    // staff with no assigned venue: nothing to count
            $names = [];
            $params = [];
            foreach (array_values($venueIds) as $i => $v) { $n = ":v$i"; $names[] = $n; $params[$n] = (int)$v; }
            return (int)db_query(
                "SELECT COUNT(*) FROM booking_messages m
                 JOIN holds h ON h.id=m.hold_id JOIN units u ON u.id=h.unit_id JOIN rooms r ON r.id=u.room_id
                 WHERE m.sender='guest' AND m.read_by_admin=FALSE AND r.venue_id IN (" . implode(',', $names) . ")",
                $params
            )->fetchColumn();
        }
        return (int)db_query("SELECT COUNT(*) FROM booking_messages WHERE sender='guest' AND read_by_admin=FALSE")->fetchColumn();
    } catch (Throwable $e) { return 0; }
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

/**
 * Published activities for the portal, scoped to a property. $venueId null = all.
 * A tour with no tour_venues rows shows everywhere; otherwise only at its venues.
 * The venue clause is added in PHP (not `:vid IS NULL`) so :vid binds exactly once
 * — pgsql can't infer the type of a NULL-compared placeholder and won't reuse names.
 */
function fetch_portal_activities(?int $venueId = null): array {
    $venueClause = '';
    $params = [];
    if ($venueId !== null) {
        $venueClause = " AND (NOT EXISTS (SELECT 1 FROM tour_venues tv WHERE tv.tour_id = t.id)
                              OR EXISTS  (SELECT 1 FROM tour_venues tv WHERE tv.tour_id = t.id AND tv.venue_id = :vid))";
        $params[':vid'] = $venueId;
    }
    try {
        return db_query(
            "SELECT t.id, t.slug, t.name, t.category, t.tag_label, t.duration, t.short_desc, t.long_desc,
                    t.price_amount, t.price_per_person, t.max_pax, t.whats_included,
                    (SELECT filename FROM tour_images ti WHERE ti.tour_id = t.id AND ti.is_hero = TRUE LIMIT 1) AS hero
             FROM tours t
             WHERE t.is_published = TRUE{$venueClause}
             ORDER BY t.sort_order ASC, t.name ASC",
            $params
        )->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** A published activity available at $venueId, by slug — else false (request-time validation). */
function fetch_tour_for_booking(string $slug, ?int $venueId): array|false {
    $venueClause = '';
    $params = [':slug' => $slug];
    if ($venueId !== null) {
        $venueClause = " AND (NOT EXISTS (SELECT 1 FROM tour_venues tv WHERE tv.tour_id = t.id)
                              OR EXISTS  (SELECT 1 FROM tour_venues tv WHERE tv.tour_id = t.id AND tv.venue_id = :vid))";
        $params[':vid'] = $venueId;
    }
    try {
        $r = db_query(
            "SELECT t.* FROM tours t WHERE t.slug = :slug AND t.is_published = TRUE{$venueClause}",
            $params
        )->fetch();
    } catch (Throwable $e) { return false; }
    return $r ?: false;
}

/** Venue ids an activity is offered at (empty = all properties). For the admin checklist. */
function activity_venue_ids(int $tourId): array {
    try {
        return array_map('intval', db_query("SELECT venue_id FROM tour_venues WHERE tour_id = :t", [':t' => $tourId])->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return []; }
}

/** Booking total for an activity given pax: per-person × pax, or the flat amount; null when unpriced. */
function activity_price_total(array $tour, int $pax): ?float {
    if (!isset($tour['price_amount']) || $tour['price_amount'] === null || $tour['price_amount'] === '') return null;
    $amt = (float) $tour['price_amount'];
    $pp  = $tour['price_per_person'] ?? false;
    $perPerson = ($pp === true || $pp === 't' || $pp === 'true' || $pp === 1 || $pp === '1');
    return $perPerson ? $amt * max(1, $pax) : $amt;
}

/**
 * Insert a booking_addon, including price_amount / pax only when those columns
 * exist (so every kind works pre- and post-migration). Returns the new id.
 */
function insert_booking_addon(array $d): int {
    $cols = ['hold_id', 'kind', 'tour_id', 'details', 'scheduled_for'];
    $vals = [':h', ':k', ':t', ':d', ':sf'];
    $p = [
        ':h' => $d['hold_id'], ':k' => $d['kind'], ':t' => $d['tour_id'] ?? null,
        ':d' => $d['details'] ?? '', ':sf' => $d['scheduled_for'] ?? null,
    ];
    if (addon_price_supported()) { $cols[] = 'price_amount'; $vals[] = ':price'; $p[':price'] = $d['price_amount'] ?? null; }
    if (addon_pax_supported())   { $cols[] = 'pax';          $vals[] = ':pax';   $p[':pax']   = $d['pax'] ?? null; }
    if (addon_board_supported())  { $cols[] = 'board_post_id'; $vals[] = ':bp'; $p[':bp'] = $d['board_post_id'] ?? null; }
    if (addon_assigned_supported()) { $cols[] = 'assigned_to'; $vals[] = ':asg'; $p[':asg'] = $d['assigned_to'] ?? null; }
    db_query('INSERT INTO booking_addons (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')', $p);
    return (int) db()->lastInsertId();
}

/**
 * Published guest-board posts visible to a guest at the given venue.
 * $venueId null (or a venue with no targeted posts) → only global posts (venue_id IS NULL).
 */
function fetch_guest_board(?int $venueId): array {
    $where = "WHERE is_published = TRUE AND (venue_id IS NULL OR venue_id = :venue)
              ORDER BY sort_order DESC, created_at DESC LIMIT 6";
    try {
        return db_query(
            "SELECT id, category, title, body, image_filename, event_date, price_amount
             FROM guest_board_posts {$where}", [':venue' => $venueId]
        )->fetchAll();
    } catch (Throwable $e) {
        // Pre-migration: event_date/price_amount columns don't exist yet — fall back
        // so the existing board still renders (there can be no 'event' rows anyway).
        return db_query(
            "SELECT id, category, title, body, image_filename
             FROM guest_board_posts {$where}", [':venue' => $venueId]
        )->fetchAll();
    }
}

/** A published 'event' board post available at the venue, by id — else false. */
function fetch_board_event(int $postId, ?int $venueId): array|false {
    $sql = "SELECT * FROM guest_board_posts WHERE id = :id AND category = 'event' AND is_published = TRUE";
    $params = [':id' => $postId];
    if ($venueId !== null) { $sql .= " AND (venue_id IS NULL OR venue_id = :v)"; $params[':v'] = $venueId; }
    else                   { $sql .= " AND venue_id IS NULL"; }
    try { $r = db_query($sql, $params)->fetch(); } catch (Throwable $e) { return false; }
    return $r ?: false;
}

/** Whether the guest already has an active (non-declined/cancelled) join for an event. */
function guest_joined_event(int $holdId, int $postId): bool {
    try {
        return (bool) db_query(
            "SELECT 1 FROM booking_addons
             WHERE hold_id = :h AND board_post_id = :p AND status NOT IN ('declined','cancelled') LIMIT 1",
            [':h' => $holdId, ':p' => $postId]
        )->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/**
 * Per-property stay info + location. Always returns the six keys as strings
 * (blank when the venue is null, missing, or the columns predate the migration).
 */
function fetch_venue_stay(?int $venueId): array {
    $blank = ['address'=>'','maps_url'=>'','stay_wifi'=>'','stay_checkout'=>'','stay_house_rules'=>'','stay_area_guide'=>''];
    if ($venueId === null) return $blank;
    try {
        $row = db_query(
            "SELECT address, maps_url, stay_wifi, stay_checkout, stay_house_rules, stay_area_guide
             FROM venues WHERE id = :id",
            [':id' => $venueId]
        )->fetch();
    } catch (Throwable $e) {
        return $blank; // columns absent pre-migration
    }
    if (!$row) return $blank;
    foreach ($blank as $k => $_) { $blank[$k] = trim((string)($row[$k] ?? '')); }
    return $blank;
}

/**
 * A tappable Google Maps URL for a venue-stay row: the owner's stored maps_url
 * if present, else a Maps search for the address, else '' (render no link).
 */
function venue_maps_link(array $stay): string {
    $url = trim((string)($stay['maps_url'] ?? ''));
    // Only honour an http(s) link — a stored javascript:/data: value must never
    // reach a guest-facing href (htmlspecialchars does not neutralise those schemes).
    if ($url !== '' && preg_match('#^https?://#i', $url)) return $url;
    $addr = trim((string)($stay['address'] ?? ''));
    if ($addr !== '') return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($addr);
    return '';
}

/**
 * Open a concierge request's message thread by posting the guest's request as
 * the first message. Unread for staff, read for the guest who just sent it.
 */
function seed_request_message(int $holdId, int $addonId, string $body): void {
    db_query(
        "INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin)
         VALUES (:h, :a, 'guest', :b, TRUE, FALSE)",
        [':h' => $holdId, ':a' => $addonId, ':b' => $body]
    );
}

/** Post an admin (staff/system) message into a request/general thread. Unread for the guest. */
function post_admin_message(int $holdId, ?int $addonId, string $body): void {
    db_query(
        "INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin)
         VALUES (:h, :a, 'admin', :b, FALSE, TRUE)",
        [':h' => $holdId, ':a' => $addonId, ':b' => $body]
    );
}

/** Fixed auto-message posted when staff change a request's status. '' = post nothing. */
function request_status_message(string $status): string {
    return [
        'confirmed' => 'Confirmed ✓ — we’ll take care of it.',
        'completed' => 'Marked as done ✓',
        'declined'  => 'Sorry, we can’t fulfil this request.',
        'cancelled' => 'This request was cancelled.',
    ][$status] ?? '';
}

/** The addon behind a request thread (with tour name), hold-scoped — for the conversation header. */
function fetch_addon_for_thread(int $holdId, int $addonId): array|false {
    $r = db_query(
        "SELECT ba.*, t.name AS tour_name
         FROM booking_addons ba LEFT JOIN tours t ON t.id = ba.tour_id
         WHERE ba.id = :a AND ba.hold_id = :h",
        [':a' => $addonId, ':h' => $holdId]
    )->fetch();
    return $r ?: false;
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

/** A booking's chargeable requests (confirmed/completed), with tour name. For the bill. */
function fetch_bill_lines(int $holdId): array {
    try {
        return db_query(
            "SELECT ba.*, t.name AS tour_name FROM booking_addons ba
             LEFT JOIN tours t ON t.id = ba.tour_id
             WHERE ba.hold_id = :h AND ba.status IN ('confirmed','completed')
             ORDER BY ba.created_at", [':h' => $holdId]
        )->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** Ad-hoc bill line items for a booking (minibar, damages…). */
function fetch_bill_items(int $holdId): array {
    try { return db_query("SELECT * FROM bill_items WHERE hold_id = :h ORDER BY id", [':h' => $holdId])->fetchAll(); }
    catch (Throwable $e) { return []; }
}

/** Total extras = confirmed/completed request prices (null → 0) + manual items. */
function bill_total(int $holdId): float {
    $t = 0.0;
    foreach (fetch_bill_lines($holdId) as $l) { $t += (float)($l['price_amount'] ?? 0); }
    foreach (fetch_bill_items($holdId) as $i) { $t += (float)($i['amount'] ?? 0); }
    return $t;
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
