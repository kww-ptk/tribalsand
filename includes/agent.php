<?php
declare(strict_types=1);
/**
 * Travel-agent portal — an EXTERNAL, read-only audience with its own login and
 * its own minimal pages under /agent, entirely separate from /admin.
 *
 * Two rules make this safe and correct, and both are load-bearing:
 *  1. Identity isolation. Agents live in their own `travel_agents` table and
 *     their session key is `agent_id`, never `admin_id`. An agent session
 *     therefore satisfies none of the admin guards (require_login checks
 *     admin_id), so an agent can never reach an /admin page, and vice-versa.
 *  2. ONE price. An agent's rate is ALWAYS the published price × (1 − discount)
 *     resolved at render time from the same room_stay_quote()/rates path the
 *     public site uses — never a stored parallel net-rate that could drift when
 *     a base rate changes.
 *
 * Every read is wrapped so a pre-migration database (no travel_agents table)
 * degrades to "feature off" rather than fataling.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';   // session_init(), is_rate_limited(), login_attempts, client_ip()
require_once __DIR__ . '/rates.php';    // rates_window_ymd(); room_stay_quote() lives in db.php
require_once __DIR__ . '/services.php'; // format_price()

/** True once add_travel_agents.sql is applied (memoised). */
function agents_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.travel_agents')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/** The signed-in agent row, or false. Revalidated every request (like current_admin). */
function agent_current(): array|false {
    session_init();
    if (empty($_SESSION['agent_id']) || !agents_supported()) return false;
    try {
        $a = db_query('SELECT * FROM travel_agents WHERE id = :id', [':id' => $_SESSION['agent_id']])->fetch();
    } catch (Throwable $e) { return false; }
    if (!$a || !$a['is_active']) return false;
    return $a;
}

/** Bounce to the agent login unless a live, active agent session exists. */
function agent_require_login(): void {
    if (!agent_current()) {
        header('Location: /agent/login.php');
        exit;
    }
}

/**
 * Authenticate an agent. Mirrors login() exactly — the shared login_attempts
 * throttle (is_rate_limited by email OR ip) and password_verify — but sets
 * `agent_id`, never `admin_id`. Returns true on success.
 */
function agent_login(string $email, string $password, string $ip): bool {
    session_init();
    if (!agents_supported()) return false;
    $email = strtolower(trim($email));
    if ($email === '' || is_rate_limited($email, $ip)) {
        db_query('INSERT INTO login_attempts (email, ip_address, success) VALUES (:e,:ip,FALSE)',
            [':e' => ($email !== '' ? $email : 'agent'), ':ip' => $ip]);
        return false;
    }
    try {
        $a = db_query('SELECT * FROM travel_agents WHERE email = :e', [':e' => $email])->fetch();
    } catch (Throwable $e) { $a = false; }

    $ok = $a && $a['is_active'] && password_verify($password, (string)$a['password_hash']);
    db_query('INSERT INTO login_attempts (email, ip_address, success) VALUES (:e,:ip,:ok)',
        [':e' => $email, ':ip' => $ip, ':ok' => $ok ? 'TRUE' : 'FALSE']);
    if (!$ok) return false;

    session_regenerate_id(true);
    $_SESSION['agent_id'] = (int)$a['id'];
    try { db_query('UPDATE travel_agents SET last_login_at = NOW() WHERE id = :id', [':id' => $a['id']]); }
    catch (Throwable $e) { /* last_login is best-effort */ }
    return true;
}

/** End an agent session without touching any (unrelated) admin session key. */
function agent_logout(): void {
    session_init();
    unset($_SESSION['agent_id']);
}

/**
 * The effective discount % for an agent at a venue: the per-venue override when
 * one is set, else the flat discount_pct. Clamped to 0..100 defensively (the DB
 * CHECK already enforces it, but venue_discounts is free JSON).
 */
function agent_discount_pct(array $agent, ?int $venueId = null): float {
    $pct = (float)($agent['discount_pct'] ?? 0);
    if ($venueId !== null) {
        $overrides = $agent['venue_discounts'] ?? null;
        if (is_string($overrides)) $overrides = json_decode($overrides, true);
        if (is_array($overrides) && isset($overrides[(string)$venueId]) && is_numeric($overrides[(string)$venueId])) {
            $pct = (float)$overrides[(string)$venueId];
        }
    }
    return max(0.0, min(100.0, $pct));
}

/**
 * An agent's net price: the published figure × (1 − discount). The published
 * figure MUST come from the one pricing path (room_stay_quote/rates); this only
 * applies the agent's percentage, currency-agnostically (a % never crosses
 * currencies, so USD and KES rooms are both correct).
 */
function agent_net_price(float $published, array $agent, ?int $venueId = null): float {
    return round($published * (1 - agent_discount_pct($agent, $venueId) / 100), 2);
}

/* ───────────────────────── Availability + requests ───────────────────────── */

/** Longest stay the portal will request — the same cap as room_max_stay_nights(). */
const AGENT_MAX_STAY_NIGHTS = 30;

/**
 * Validate a requested stay the way the public endpoints do (rates_window_ymd()
 * on both dates, check-out after check-in) plus the two rules a BOOKING needs:
 * it cannot start before today (Nairobi-local — includes/db.php sets the zone)
 * and it is capped at AGENT_MAX_STAY_NIGHTS. Returns [check_in, check_out, nights]
 * normalised to zero-padded Y-m-d, or null. Pure ($today only for tests).
 */
function agent_valid_stay(string $checkIn, string $checkOut, ?string $today = null): ?array {
    $ci = rates_window_ymd($checkIn);
    $co = rates_window_ymd($checkOut);
    if ($ci === null || $co === null || $ci >= $co) return null;
    if ($ci < ($today ?? date('Y-m-d'))) return null;
    $nights = (int) round((strtotime($co) - strtotime($ci)) / 86400);
    if ($nights < 1 || $nights > AGENT_MAX_STAY_NIGHTS) return null;
    return [$ci, $co, $nights];
}

/**
 * Apply an agent's discount to a ts_property_configurations() result: the same
 * shape back, with `discount_pct` at the top and `net_total` beside every
 * published `total` (singles, entire, each combo and each combo room). Published
 * figures are never altered — the portal shows both. Pure: the totals in are
 * already the ONE pricing path's output; this only applies the percentage.
 */
function agent_price_configurations(array $cfg, array $agent, int $venueId): array {
    $net = fn($total): float => agent_net_price((float)$total, $agent, $venueId);
    foreach (['singles', 'entire'] as $section) {
        $items = is_array($cfg[$section] ?? null) ? $cfg[$section] : [];
        foreach ($items as $i => $item) $items[$i]['net_total'] = $net($item['total'] ?? 0);
        $cfg[$section] = $items;
    }
    $combos = is_array($cfg['combos'] ?? null) ? $cfg['combos'] : [];
    foreach ($combos as $i => $combo) {
        $combos[$i]['net_total'] = $net($combo['total'] ?? 0);
        $rooms = is_array($combo['rooms'] ?? null) ? $combo['rooms'] : [];
        foreach ($rooms as $j => $r) $rooms[$j]['net_total'] = $net($r['total'] ?? 0);
        $combos[$i]['rooms'] = $rooms;
    }
    $cfg['combos']       = $combos;
    $cfg['discount_pct'] = agent_discount_pct($agent, $venueId);
    return $cfg;
}

/** Combo rooms → "slug:units,slug:units" for a "Request these rooms" link. Pure. */
function agent_rooms_param(array $rooms): string {
    $parts = [];
    foreach ($rooms as $r) {
        $slug = (string)($r['slug'] ?? '');
        if ($slug === '') continue;
        $parts[] = $slug . ':' . max(1, (int)($r['units_used'] ?? $r['units'] ?? 1));
    }
    return implode(',', $parts);
}

/**
 * "slug:2,other:1" → [['slug' => 'slug', 'units' => 2], …]. Units clamp to 1..8,
 * malformed entries are dropped, slugs keep the room-slug alphabet only. Pure.
 */
function agent_parse_rooms_param(string $s): array {
    $out = [];
    foreach (explode(',', $s) as $part) {
        $part = trim($part);
        if ($part === '' || !preg_match('/^([a-z0-9][a-z0-9_-]*)(?::(\d{1,2}))?$/i', $part, $m)) continue;
        $out[] = ['slug' => strtolower($m[1]), 'units' => max(1, min(8, (int)($m[2] ?? 1)))];
    }
    return $out;
}

/** 12.50 → "12.5", 10.00 → "10". Pure. */
function agent_pct_label(float $pct): string {
    return rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.');
}

/**
 * The two human-readable lines every trade request carries into the staff email,
 * the stored message and admin: who booked it, and at what rate. Pure.
 * $quote: nights, published, net, currency, discount_pct (agent_stay_quote() shape).
 */
function agent_trade_lines(array $agent, array $quote): array {
    $name   = trim((string)($agent['name'] ?? ''));
    $agency = trim((string)($agent['agency'] ?? ''));
    $email  = trim((string)($agent['email'] ?? ''));
    $who    = $agency !== '' ? $agency . ' — ' . $name : $name;
    if ($email !== '') $who .= ' <' . $email . '>';

    $cur       = (string)($quote['currency'] ?? 'USD');
    $nights    = (int)($quote['nights'] ?? 0);
    $pct       = (float)($quote['discount_pct'] ?? 0);
    $nightsTxt = $nights . ' night' . ($nights === 1 ? '' : 's');
    // An unpriced room (no base rate set) is "on request" — never "USD 0 net".
    if ((float)($quote['published'] ?? 0) <= 0) {
        $rate = 'Price on request · ' . $nightsTxt . ($pct > 0 ? ' · ' . agent_pct_label($pct) . '% trade discount applies' : '');
    } elseif ($pct > 0) {
        $rate = format_price((float)($quote['net'] ?? 0), $cur) . ' net · ' . $nightsTxt . ' · '
              . agent_pct_label($pct) . '% off published ' . format_price((float)($quote['published'] ?? 0), $cur);
    } else {
        $rate = format_price((float)($quote['published'] ?? 0), $cur) . ' · ' . $nightsTxt . ' · published rate (no trade discount)';
    }
    return ['agent' => $who, 'rate' => $rate];
}

/**
 * Trade-friendly labels for the admin lead-status pipeline, used BEFORE a hold
 * exists so the portal reflects what reservations are actually doing (not a
 * permanent "Sent"). Internal slugs never leak — only these labels/classes.
 * Pure. Returns null for an unrecognised/empty slug so the caller keeps "Sent".
 */
function agent_request_sub_status(?string $slug): ?array {
    return match ((string)$slug) {
        'received'                                          => ['label' => 'Sent',              'class' => 'sent',      'note' => 'Reservations will confirm by email'],
        'answered', 'option_sent', 'waiting', 'to_follow_up' => ['label' => 'In review',         'class' => 'pending',   'note' => 'Reservations are working on this'],
        'dates_unavailable'                                 => ['label' => 'Dates unavailable', 'class' => 'expired',   'note' => 'Try other dates or ask reservations'],
        'not_interested'                                    => ['label' => 'Closed',            'class' => 'cancelled', 'note' => ''],
        'booked'                                            => ['label' => 'Booked',            'class' => 'confirmed', 'note' => ''],
        default                                             => null,
    };
}

/**
 * Portal-facing state of one agent_requests() row: the hold's status once a hold
 * exists (with a countdown while pending); before that, the admin lead status
 * mapped to a trade-friendly label (agent_request_sub_status()), or "Sent" when
 * there is no status yet. The hold always wins — it carries the countdown and
 * confirmation. Pure — pass $now for tests; reads $row['sub_status'] when present.
 */
function agent_request_status(array $row, ?int $now = null): array {
    $now = $now ?? time();
    $st  = (string)($row['hold_status'] ?? '');
    if ($st === '') {
        return agent_request_sub_status($row['sub_status'] ?? null)
            ?? ['label' => 'Sent', 'class' => 'sent', 'note' => 'Reservations will confirm by email'];
    }
    if ($st === 'pending') {
        $exp = !empty($row['expires_at']) ? strtotime((string)$row['expires_at']) : false;
        if ($exp === false) return ['label' => 'On hold', 'class' => 'pending', 'note' => 'Awaiting confirmation'];
        $left = $exp - $now;
        $note = $left > 0
            ? 'Expires in ' . intdiv($left, 3600) . 'h ' . str_pad((string)intdiv($left % 3600, 60), 2, '0', STR_PAD_LEFT) . 'm'
            : 'Expiring…';
        return ['label' => 'On hold', 'class' => 'pending', 'note' => $note];
    }
    return match ($st) {
        'confirmed' => ['label' => 'Confirmed', 'class' => 'confirmed', 'note' => ''],
        'expired'   => ['label' => 'Expired',   'class' => 'expired',   'note' => 'Not confirmed in time — search again'],
        'cancelled' => ['label' => 'Cancelled', 'class' => 'cancelled', 'note' => ''],
        default     => ['label' => ucfirst($st), 'class' => 'expired', 'note' => ''],
    };
}

/** Map a portal status class (agent_request_status) to an admin .badge class. */
function agent_status_badge(string $class): string {
    return match ($class) {
        'confirmed' => 'badge--green',
        'pending'   => 'badge--orange',
        'sent'      => 'badge--blue',
        default     => 'badge--grey',   // expired / cancelled
    };
}

/**
 * An agent's quote for one room over a stay: the published total from
 * room_stay_quote() — the ONE pricing path, override-aware — and the net total
 * with the agent's discount for that room's property. nights === 0 means "not a
 * quote" (unparseable / reversed window): callers must reject it before showing
 * or storing a price — neither a $0 stay nor an epoch night-count is safe.
 * $room needs id, venue_id, price_amount, price_currency (fetch_room_by_slug()).
 */
function agent_stay_quote(array $room, array $agent, string $checkIn, string $checkOut): array {
    $venueId   = (int)($room['venue_id'] ?? 0);
    $q         = room_stay_quote((int)$room['id'], (float)($room['price_amount'] ?? 0), $checkIn, $checkOut);
    $published = round((float)$q['total'], 2);
    return [
        'nights'       => (int)$q['nights'],
        'published'    => $published,
        'net'          => (int)$q['nights'] > 0 ? agent_net_price($published, $agent, $venueId) : 0.0,
        'currency'     => (string)(($room['price_currency'] ?? '') ?: 'USD'),
        'discount_pct' => agent_discount_pct($agent, $venueId),
    ];
}

/** Abuse guards for the authenticated (Turnstile-less) portal. */
const AGENT_MAX_REQUESTS_PER_WINDOW = 12;   // per 10 minutes
const AGENT_DEDUPE_SECONDS          = 30;   // an identical re-send inside this window is a double submit

/**
 * The HMAC marker the portal writes into a request's payload. Any public form can
 * post an `agent_id` key (api/trip-builder.php stores its POST body wholesale),
 * so a bare payload id must never decide whose request a row is; this cannot be
 * produced without the booking-token secret.
 */
function agent_request_sig(int $agentId): string {
    $secret = (string)(parse_env()['BOOKING_TOKEN_SECRET'] ?? '');
    return hash_hmac('sha256', 'trade-portal:' . $agentId, $secret !== '' ? $secret : 'trade-portal');
}

/**
 * SQL that selects an agent's own requests, as [fragment, params] for alias $s.
 * Keyed on the SERVER-written submissions.agent_id once the migration has run;
 * rows written before the column existed are matched by payload id + the HMAC
 * marker. A payload id on its own is never enough (see agent_request_sig()).
 */
function agent_requests_filter(int $agentId, string $s = 's'): array {
    $params    = [':aid_txt' => (string)$agentId, ':sig' => agent_request_sig($agentId)];
    $byPayload = "({$s}.payload_json->>'agent_id' = :aid_txt AND {$s}.payload_json->>'agent_sig' = :sig)";
    if (!submissions_agent_supported()) return [$byPayload, $params];
    $params[':aid'] = $agentId;
    return ["({$s}.agent_id = :aid OR ({$s}.agent_id IS NULL AND {$byPayload}))", $params];
}

/**
 * A room the portal will book: published, and not one of the six per-bedroom
 * Maya Ilai products. Those own no units (they slice the villa's) and are priced
 * by the guest configurator, not the rate card — ts_property_configurations()
 * cannot list them, so the writer must not book them by URL either.
 */
function agent_room_bookable(array $room): bool {
    if (empty($room['is_published'])) return false;
    if (mi_is_composite_room($room)) {
        try { if (count(fetch_units_by_room((int)$room['id'])) === 0) return false; }
        catch (Throwable $e) { return false; }
    }
    return true;
}

/**
 * The same agent re-sending the same request — same product (or room set) and
 * dates — within AGENT_DEDUPE_SECONDS is a double submit, not a second booking.
 * Returns the earlier request so the caller reuses it instead of writing a
 * second submission, a second hold and a second pair of emails. Keyed on what
 * was actually asked for: the guest helper keys on email + dates only, which
 * would swallow a legitimate second room for the same client and dates.
 */
function agent_recent_duplicate(int $agentId, string $ci, string $co, ?int $roomId, string $roomsLabel): ?array {
    [$where, $params] = agent_requests_filter($agentId);
    try {
        $row = db_query(
            "SELECT s.id,
                    (SELECT h.id FROM holds h WHERE h.submission_id = s.id ORDER BY h.id DESC LIMIT 1) AS hold_id
               FROM submissions s
              WHERE {$where}
                AND s.check_in = :ci AND s.check_out = :co
                AND COALESCE(s.room_id, 0) = :room
                AND COALESCE(s.payload_json->>'rooms', '') = :rooms
                AND s.created_at > :win
              ORDER BY s.id DESC LIMIT 1",
            $params + [':ci' => $ci, ':co' => $co, ':room' => (int)$roomId, ':rooms' => $roomsLabel,
                       ':win' => date('Y-m-d H:i:s', time() - AGENT_DEDUPE_SECONDS)]
        )->fetch();
    } catch (Throwable $e) {
        error_log('[agent-request] dedupe check failed: ' . $e->getMessage());
        return null;
    }
    return $row ? ['submission_id' => (int)$row['id'], 'hold_id' => $row['hold_id'] !== null ? (int)$row['hold_id'] : null] : null;
}

/**
 * Per-agent throttle. The portal is authenticated, so there is no Turnstile —
 * but a scripted or leaked session must not be able to flood the inbox (each
 * request e-mails reservations). Returns the refusal message, or null when
 * within limits. Fails OPEN on a read error (like concierge_rate_limited()).
 */
function agent_request_throttled(array $agent, int $maxRequests = AGENT_MAX_REQUESTS_PER_WINDOW): ?string {
    $aid = (int)($agent['id'] ?? 0);
    try {
        [$where, $params] = agent_requests_filter($aid);
        $recent = (int) db_query(
            "SELECT COUNT(*) FROM submissions s WHERE {$where} AND s.created_at > :win",
            $params + [':win' => date('Y-m-d H:i:s', time() - 600)]
        )->fetchColumn();
        if ($recent >= $maxRequests) {
            return 'You have sent several requests in the last few minutes. Please wait a little before sending more, or email reservations for a large group.';
        }
    } catch (Throwable $e) {
        error_log('[agent-request] throttle check failed: ' . $e->getMessage());
    }
    return null;
}

/**
 * Turn an agent's "Request to book" into a booking REQUEST for reservations: one
 * submission (type 'enquiry'; server-written agent_id; payload.agent_* carries
 * the trade facts including the net quote). It NEVER places a hold — the owner's
 * rule is that a trade request must not block inventory by itself. Reservations
 * review it in the inbox and place the hold with "Convert to Hold", which
 * agent_tag_converted_hold() links to the agent at their frozen net price.
 *
 * $req: kind 'room' (room_slug) | 'combo' (venue_slug + rooms [['slug','units'],…]),
 *       check_in, check_out, adults, children, guest_name (the traveller, required),
 *       guest_email, guest_phone, notes.
 *
 * Contact of record = the AGENT: guest_email is the agent's login email (all
 * automatic e-mails and admin replies reach the trade partner), guest_name is the
 * traveller, and the traveller's own contact details go into the payload and the
 * message for reception. Never sends e-mail — the caller does, after the write.
 *
 * Returns ['ok'=>true, submission_id, mode 'enquiry', quote, trade, lines, venue,
 * rooms_label, message, check_in, check_out, adults, children, traveller, notes]
 * (+ 'dedupe'=>true when an identical re-send was reused)
 * or ['ok'=>false, error, code 403|422|429|500].
 */
function agent_submit_request(array $agent, array $req, array $tracking = []): array {
    $err = fn(string $m, int $c = 422): array => ['ok' => false, 'error' => $m, 'code' => $c];
    $agentId    = (int)($agent['id'] ?? 0);
    $agentEmail = strtolower(trim((string)($agent['email'] ?? '')));
    if ($agentId <= 0 || !filter_var($agentEmail, FILTER_VALIDATE_EMAIL)) return $err('Please sign in again.', 403);

    $stay = agent_valid_stay((string)($req['check_in'] ?? ''), (string)($req['check_out'] ?? ''));
    if ($stay === null) {
        return $err('Please choose a valid check-in and a later check-out — not in the past and up to ' . AGENT_MAX_STAY_NIGHTS . ' nights.');
    }
    [$ci, $co, $nights] = $stay;

    $adults    = max(1, min(30, (int)($req['adults'] ?? 1)));
    $children  = max(0, min(20, (int)($req['children'] ?? 0)));
    $traveller = mb_substr(trim((string)($req['guest_name'] ?? '')), 0, 255);
    if ($traveller === '') return $err('The travelling guest’s name is required.');
    $tEmail = trim((string)($req['guest_email'] ?? ''));
    if ($tEmail !== '' && !filter_var($tEmail, FILTER_VALIDATE_EMAIL)) return $err('The traveller’s email address doesn’t look right.');
    $tPhone = mb_substr(trim((string)($req['guest_phone'] ?? '')), 0, 50);
    $notes  = mb_substr(trim((string)($req['notes'] ?? '')), 0, 2000);

    // ── Resolve the product(s) and price them server-side — the ONE path ──
    $kind  = (($req['kind'] ?? 'room') === 'combo') ? 'combo' : 'room';
    $lines = [];   // [['room' => row, 'units' => int, 'quote' => agent_stay_quote()], …]
    if ($kind === 'room') {
        $room = fetch_room_by_slug(trim((string)($req['room_slug'] ?? '')));
        if (!$room || !agent_room_bookable($room)) return $err('That room isn’t available to book through the trade portal — please choose another room or email reservations.');
        $venue = db_query('SELECT id, slug, name FROM venues WHERE id = :id AND is_published = TRUE',
            [':id' => $room['venue_id']])->fetch();
        if (!$venue) return $err('That property isn’t available to book.');
        $lines[] = ['room' => $room, 'units' => 1, 'quote' => agent_stay_quote($room, $agent, $ci, $co)];
    } else {
        $venue = db_query('SELECT id, slug, name FROM venues WHERE slug = :s AND is_published = TRUE',
            [':s' => trim((string)($req['venue_slug'] ?? ''))])->fetch();
        if (!$venue) return $err('That property isn’t available to book.');
        foreach ((is_array($req['rooms'] ?? null) ? $req['rooms'] : []) as $pick) {
            $room = fetch_room_by_slug(trim((string)($pick['slug'] ?? '')));
            if (!$room || !agent_room_bookable($room) || (int)$room['venue_id'] !== (int)$venue['id']) {
                return $err('One of those rooms isn’t available at this property.');
            }
            $lines[] = ['room' => $room, 'units' => max(1, min(8, (int)($pick['units'] ?? 1))),
                        'quote' => agent_stay_quote($room, $agent, $ci, $co)];
        }
        if (!$lines) return $err('Choose at least one room.');
    }
    $currency  = (string)$lines[0]['quote']['currency'];
    $published = 0.0;
    $net       = 0.0;
    foreach ($lines as $l) {
        if ((int)$l['quote']['nights'] === 0) return $err('We couldn’t price those dates. Please try again.');
        // Money is never summed across currencies.
        if ($l['quote']['currency'] !== $currency) return $err('Those rooms are priced in different currencies and can’t be requested together.');
        $published += $l['quote']['published'] * $l['units'];
        $net       += $l['quote']['net']       * $l['units'];
    }
    $venueId = (int)$venue['id'];
    $quote   = ['nights' => $nights, 'published' => round($published, 2), 'net' => round($net, 2),
                'currency' => $currency, 'discount_pct' => agent_discount_pct($agent, $venueId)];
    $trade   = agent_trade_lines($agent, $quote);
    $room    = $lines[0]['room'];

    $roomsLabel = implode(', ', array_map(
        fn($l) => $l['room']['name'] . ($l['units'] > 1 ? ' ×' . $l['units'] : ''), $lines));

    // What staff read first — in the notification e-mail and the inbox.
    $msg = ['Trade booking request via the agent portal — no hold placed: please check the dates and convert this request to a hold.',
            'Agent: ' . $trade['agent'],
            'Traveller: ' . $traveller . ($tEmail !== '' ? ' · ' . $tEmail : '') . ($tPhone !== '' ? ' · ' . $tPhone : ''),
            'Rate: ' . $trade['rate']];
    if ($kind === 'combo') $msg[] = 'Rooms: ' . $roomsLabel;
    if ($notes !== '') { $msg[] = ''; $msg[] = 'Agent note: ' . $notes; }
    $message = implode("\n", $msg);

    $payload = array_filter([
        'source'          => 'trade-portal',
        'agent_id'        => $agentId,
        'agent_name'      => (string)($agent['name'] ?? ''),
        'agency'          => (string)($agent['agency'] ?? ''),
        'agent_email'     => $agentEmail,
        'agent_sig'       => agent_request_sig($agentId),
        'venue'           => (string)$venue['name'],
        'traveller_email' => $tEmail,
        'traveller_phone' => $tPhone,
        'discount_pct'    => $quote['discount_pct'],
        'published_total' => $quote['published'],
        'quoted_total'    => $quote['net'],
        'quoted_currency' => $currency,
        'quoted_label'    => $nights . ' night' . ($nights === 1 ? '' : 's')
            . ($quote['published'] <= 0
                ? ' · price on request'
                : ' · trade net rate' . ($quote['discount_pct'] > 0
                    ? ' (' . agent_pct_label($quote['discount_pct']) . '% off published ' . format_price($quote['published'], $currency) . ')'
                    : '')),
        'rooms'           => $kind === 'combo' ? $roomsLabel : '',
    ], fn($v) => $v !== '' && $v !== null);

    // A double submit (same product/rooms + dates inside the window) reuses the
    // earlier request rather than writing a second one and mailing everyone twice.
    $dup = agent_recent_duplicate($agentId, $ci, $co, $kind === 'room' ? (int)$room['id'] : null,
        $kind === 'combo' ? $roomsLabel : '');
    if ($dup !== null) {
        return ['ok' => true, 'dedupe' => true, 'submission_id' => $dup['submission_id'], 'mode' => 'enquiry'];
    }
    if (($throttled = agent_request_throttled($agent)) !== null) return $err($throttled, 429);

    $writeSubAgent = submissions_agent_supported();
    try {
        db_query(
            "INSERT INTO submissions
                (" . ($writeSubAgent ? 'agent_id, ' : '') . "type, room_id, guest_name, guest_email, guest_phone, message,
                 check_in, check_out, guests_adults, guests_children, payload_json,
                 source_page, referrer, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                 user_agent, ip_address)
             VALUES
                (" . ($writeSubAgent ? ':agent_id, ' : '') . "'enquiry', :room_id, :name, :email, :phone, :message,
                 :ci, :co, :adults, :children, :payload,
                 :source_page, :referrer, :utm_source, :utm_medium, :utm_campaign, :utm_term, :utm_content,
                 :ua, :ip)",
            ($writeSubAgent ? [':agent_id' => $agentId] : []) + [
                ':room_id'     => $kind === 'room' ? (int)$room['id'] : null,
                ':name'        => $traveller,
                ':email'       => $agentEmail,
                // Contact of record is the agent: the traveller's phone lives in the
                // payload + message, so staff never mistake it for the booker's.
                ':phone'       => '',
                ':message'     => $message,
                ':ci'          => $ci,
                ':co'          => $co,
                ':adults'      => $adults,
                ':children'    => $children,
                ':payload'     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':source_page' => (string)($tracking['source_page'] ?? ''),
                ':referrer'    => (string)($tracking['referrer'] ?? ''),
                ':utm_source'  => (string)($tracking['utm_source'] ?? 'trade-portal'),
                ':utm_medium'  => (string)($tracking['utm_medium'] ?? ''),
                ':utm_campaign'=> (string)($tracking['utm_campaign'] ?? ''),
                ':utm_term'    => (string)($tracking['utm_term'] ?? ''),
                ':utm_content' => (string)($tracking['utm_content'] ?? ''),
                ':ua'          => (string)($tracking['user_agent'] ?? ''),
                ':ip'          => (string)($tracking['ip'] ?? client_ip()),
            ]
        );
        $subId = (int)db()->lastInsertId();
    } catch (\Throwable $e) {
        error_log('[agent-request] failed: ' . $e->getMessage());
        return $err('Something went wrong saving the request. Please try again or contact reservations.', 500);
    }

    return [
        'ok' => true, 'submission_id' => $subId, 'mode' => 'enquiry',
        'quote' => $quote, 'trade' => $trade, 'lines' => $lines, 'venue' => $venue, 'rooms_label' => $roomsLabel,
        'message' => $message, 'check_in' => $ci, 'check_out' => $co,
        'adults' => $adults, 'children' => $children, 'traveller' => $traveller, 'notes' => $notes,
    ];
}

/**
 * The agent behind a submission, or 0: the server-written submissions.agent_id
 * when present, else the payload's agent_id only when its HMAC marker verifies
 * (a bare payload id is client-posted — see agent_request_sig()).
 */
function agent_submission_agent_id(array $sub): int {
    if (!empty($sub['agent_id'])) return (int)$sub['agent_id'];
    $pl = json_decode((string)($sub['payload_json'] ?? '{}'), true);
    if (!is_array($pl) || empty($pl['agent_id'])) return 0;
    $aid = (int)$pl['agent_id'];
    return hash_equals(agent_request_sig($aid), (string)($pl['agent_sig'] ?? '')) ? $aid : 0;
}

/**
 * Called by admin's "Convert to Hold" right after it creates a hold from a trade
 * request: links the hold to the agent (holds.agent_id) and freezes the agent's
 * NET price for the room actually booked (holds.quoted_amount/currency) — re-quoted
 * through agent_stay_quote(), the ONE pricing path, so a swapped room or a
 * combination's per-room hold is priced correctly, not from the request's total.
 * bookings_sync_hold() then books it as source='agent' at that figure. Each
 * column is written only when supported. Returns ['agent', 'agency', 'net',
 * 'currency', 'nights'] when the hold was tagged, null when the submission is not
 * a trade request (a guest enquiry) or nothing could be resolved.
 */
function agent_tag_converted_hold(int $holdId, array $sub): ?array {
    $aid = agent_submission_agent_id($sub);
    if ($aid <= 0 || $holdId <= 0 || !agents_supported()) return null;
    try {
        $agent = db_query('SELECT * FROM travel_agents WHERE id = :id', [':id' => $aid])->fetch();
        if (!$agent) return null;
        $h = db_query(
            "SELECT h.check_in, h.check_out, r.*
               FROM holds h JOIN units u ON u.id = h.unit_id JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u') . "
              WHERE h.id = :id", [':id' => $holdId]
        )->fetch();
        if (!$h) return null;
        $q = agent_stay_quote($h, $agent, (string)$h['check_in'], (string)$h['check_out']);

        if (holds_agent_supported()) {
            db_query('UPDATE holds SET agent_id = :a WHERE id = :id', [':a' => $aid, ':id' => $holdId]);
        }
        if ((int)$q['nights'] > 0 && $q['published'] > 0 && holds_quoted_amount_supported()) {
            db_query('UPDATE holds SET quoted_amount = :q, quoted_currency = :c WHERE id = :id',
                [':q' => $q['net'], ':c' => $q['currency'], ':id' => $holdId]);
        }
    } catch (Throwable $e) {
        error_log('[agent-convert] tagging hold ' . $holdId . ' failed: ' . $e->getMessage());
        return null;
    }
    return ['agent' => (string)$agent['name'], 'agency' => (string)($agent['agency'] ?? ''),
            'net' => (float)$q['net'], 'currency' => (string)$q['currency'], 'nights' => (int)$q['nights']];
}
/**
 * The agent's requests, newest first, selected by agent_requests_filter() — the
 * server-written submissions.agent_id, or the signed payload marker for rows
 * written before that column existed; never the bare payload id. The latest
 * hold on each submission supplies the status.
 * Each row carries a decoded `payload`.
 */
function agent_requests(array $agent, int $limit = 100): array {
    $aid = (int)($agent['id'] ?? 0);
    if ($aid <= 0) return [];
    $limit = max(1, min(500, $limit));
    [$where, $params] = agent_requests_filter($aid);
    // The admin lead status feeds the portal's status BEFORE a hold exists — but
    // only once add_submission_status.sql has run.
    require_once __DIR__ . '/submission-status.php';
    $subStatusSel = submission_status_supported() ? 's.status AS sub_status,' : "NULL::text AS sub_status,";
    $rows = db_query(
        "SELECT s.id, s.created_at, s.check_in, s.check_out, s.guest_name, s.room_id, s.payload_json,
                s.guests_adults, s.guests_children, {$subStatusSel}
                r.name AS room_name, v.name AS venue_name,
                h.id AS hold_id, h.status AS hold_status, h.expires_at, h.access_code
           FROM submissions s
           LEFT JOIN rooms  r ON r.id = s.room_id
           LEFT JOIN venues v ON v.id = r.venue_id
           LEFT JOIN LATERAL (
                SELECT id, status, expires_at, access_code FROM holds
                 WHERE submission_id = s.id ORDER BY id DESC LIMIT 1
           ) h ON TRUE
          WHERE {$where}
          ORDER BY s.created_at DESC, s.id DESC
          LIMIT {$limit}",
        $params
    )->fetchAll();
    foreach ($rows as $i => $r) {
        $pl = json_decode((string)($r['payload_json'] ?? '{}'), true);
        $rows[$i]['payload'] = is_array($pl) ? $pl : [];
    }
    return $rows;
}

/**
 * One of an agent's requests by submission id, with the SAME ownership guard the
 * list uses (agent_requests_filter — server-written agent_id, or the signed
 * payload marker; never the bare id from the URL). Returns the row (decoded
 * `payload`, hold status, sub_status) or null when it is not this agent's request.
 */
function agent_fetch_request(array $agent, int $submissionId): ?array {
    $aid = (int)($agent['id'] ?? 0);
    if ($aid <= 0 || $submissionId <= 0) return null;
    [$where, $params] = agent_requests_filter($aid);
    require_once __DIR__ . '/submission-status.php';
    $subStatusSel = submission_status_supported() ? 's.status AS sub_status,' : "NULL::text AS sub_status,";
    try {
        $row = db_query(
            "SELECT s.id, s.created_at, s.check_in, s.check_out, s.guest_name, s.room_id, s.payload_json,
                    s.guests_adults, s.guests_children, {$subStatusSel}
                    r.name AS room_name, v.name AS venue_name,
                    h.id AS hold_id, h.status AS hold_status, h.expires_at, h.access_code
               FROM submissions s
               LEFT JOIN rooms  r ON r.id = s.room_id
               LEFT JOIN venues v ON v.id = r.venue_id
               LEFT JOIN LATERAL (
                    SELECT id, status, expires_at, access_code FROM holds
                     WHERE submission_id = s.id ORDER BY id DESC LIMIT 1
               ) h ON TRUE
              WHERE s.id = :sid AND {$where}
              LIMIT 1",
            $params + [':sid' => $submissionId]
        )->fetch();
    } catch (Throwable $e) {
        error_log('[agent-request] fetch one failed: ' . $e->getMessage());
        return null;
    }
    if (!$row) return null;
    $pl = json_decode((string)($row['payload_json'] ?? '{}'), true);
    $row['payload'] = is_array($pl) ? $pl : [];
    return $row;
}

/**
 * The conversation an agent may see on their request: ONLY staff replies
 * (kind 'reply') and customer replies (kind 'guest_reply' — an inbound email or
 * the agent's own portal messages). Internal `note` rows are staff-only and are
 * NEVER returned here. [] pre-migration / on error. Ownership is the caller's job
 * (use agent_fetch_request() first).
 */
function fetch_agent_visible_thread(int $submissionId, int $afterId = 0): array {
    if ($submissionId <= 0) return [];
    require_once __DIR__ . '/submission-notes.php';
    // Before the kind column exists every row is a plain internal note, so there
    // is nothing an agent may see.
    if (!submission_notes_kind_supported()) return [];
    try {
        return db_query(
            "SELECT n.id, n.body, n.created_at, n.kind, NULLIF(n.author_name,'') AS frozen_author,
                    a.name AS author_name
               FROM submission_notes n
               LEFT JOIN admin_users a ON a.id = n.admin_id
              WHERE n.submission_id = :sid AND n.id > :after AND n.kind IN ('reply','guest_reply')
              ORDER BY n.created_at ASC, n.id ASC",
            [':sid' => $submissionId, ':after' => $afterId]
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('[agent-request] visible thread failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * The agent posts a message on their own request. Re-checks ownership, then writes
 * a `guest_reply` note (the same blue bubble reservations already read in
 * admin/submission-view.php — zero extra work there) and raises the Item-4 unread
 * flag. NEVER creates a hold (the owner's rule). Returns ['ok'=>true, note_id] or
 * ['ok'=>false, error, code].
 */
function agent_post_message(array $agent, int $submissionId, string $body): array {
    $err = fn(string $m, int $c = 422): array => ['ok' => false, 'error' => $m, 'code' => $c];
    $aid  = (int)($agent['id'] ?? 0);
    $body = trim($body);
    if ($aid <= 0) return $err('Please sign in again.', 403);
    if ($body === '') return $err('Please write a message.');
    $body = mb_substr($body, 0, 4000);

    $req = agent_fetch_request($agent, $submissionId);
    if ($req === null) return $err('That request could not be found.', 404);

    require_once __DIR__ . '/submission-notes.php';
    if (!submission_notes_supported()) return $err('Messaging is unavailable right now — please email reservations.', 503);

    $who = trim((string)($agent['name'] ?? ''));
    $agency = trim((string)($agent['agency'] ?? ''));
    $label = $agency !== '' ? ($who !== '' ? $who . ' (' . $agency . ')' : $agency) : ($who ?: 'Agent');

    $noteId = add_submission_note($submissionId, null, $body, 'guest_reply', $label);
    if (!$noteId) return $err('Could not send your message. Please try again.', 500);

    // Same unread signal an inbound guest reply raises (Item 4) — a trade reply is
    // a customer reply for reservations to see.
    submission_mark_guest_reply($submissionId);
    return ['ok' => true, 'note_id' => $noteId];
}

/**
 * After a request is saved: the staff enquiry notification (its stored message
 * opens with the trade lines) and the agent's acknowledgement, addressed to the
 * agent with the net price on record. Best-effort: a mail failure never undoes
 * a saved request.
 */
function agent_send_request_emails(array $agent, array $res): void {
    require_once __DIR__ . '/mail.php';
    $trade = $res['trade'];
    $where = $res['venue']['name'] . ' — ' . $res['rooms_label'];
    try {
        send_notification([
            'id'              => $res['submission_id'],
            'type'            => 'enquiry',
            'room_name'       => $where,
            'guest_name'      => $res['traveller'],
            'guest_email'     => (string)$agent['email'],
            'guest_phone'     => '',
            'message'         => $res['message'],
            'check_in'        => $res['check_in'],
            'check_out'       => $res['check_out'],
            'guests_adults'   => $res['adults'],
            'guests_children' => $res['children'],
            'created_at'      => date('Y-m-d H:i:s'),
            'source_page'     => 'Trade portal',
            'utm_source'      => 'trade-portal',
        ]);
        send_guest_acknowledgement([
            'kind'            => 'enquiry',
            'guest_name'      => (string)$agent['name'],
            'guest_email'     => (string)$agent['email'],
            'agency_name'     => (string)($agent['agency'] ?? ''),
            'room_name'       => $where,
            'check_in'        => $res['check_in'],
            'check_out'       => $res['check_out'],
            'guests_adults'   => $res['adults'],
            'guests_children' => $res['children'],
            'price'           => $trade['rate'],
            'message'         => 'Booking for: ' . $res['traveller'] . ($res['notes'] !== '' ? "\n" . $res['notes'] : ''),
        ]);
    } catch (Throwable $e) {
        error_log('[agent-request] mail failed: ' . $e->getMessage());
    }
}
