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
 * Portal-facing state of one agent_requests() row: the hold's status (with a
 * countdown while pending) or "Enquiry sent" when the request created no hold
 * (enquiry-mode room, or a room combination). Pure — pass $now for tests.
 */
function agent_request_status(array $row, ?int $now = null): array {
    $now = $now ?? time();
    $st  = (string)($row['hold_status'] ?? '');
    if ($st === '') return ['label' => 'Enquiry sent', 'class' => 'sent', 'note' => 'We’ll reply by email'];
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

/**
 * Whether a request for this room becomes a 24h HOLD or a plain ENQUIRY — the
 * exact rule api/submit-enquiry.php applies to the public widget: the room's own
 * form_mode, else the global `form_mode` setting, and never 'availability' when
 * the room's inventory room has no active unit to hold. The inventory question
 * goes through room_inventory_room_id(), so a Maya Ilai composite product asks
 * about the villa's units instead of silently downgrading to an enquiry.
 */
function agent_room_form_mode(array $room): string {
    $mode = !empty($room['form_mode']) ? (string)$room['form_mode'] : setting('form_mode', 'enquiry');
    if ($mode === 'availability') {
        try {
            if (count(fetch_units_by_room(room_inventory_room_id($room))) === 0) $mode = 'enquiry';
        } catch (Throwable $e) {
            $mode = 'enquiry';
        }
    }
    return $mode === 'availability' ? 'availability' : 'enquiry';
}

/** Raised inside the request transaction when the dates are taken while we write. */
class AgentSoldOutException extends \RuntimeException {}

/**
 * Turn an agent's "Request to book" into the SAME records the public widget
 * creates: one submission (type 'enquiry'; payload.agent_* carries the trade
 * facts) and — when the room is in availability mode — one 24h hold written by
 * the same allocators the guest path uses (mi_allocate_and_hold() for a Maya Ilai
 * composite room, else find_available_unit() + create_hold_with_block()). The
 * hold carries holds.agent_id and freezes the NET price in holds.quoted_amount,
 * which bookings_sync_hold() snapshots at confirm time. A room combination, or a
 * room in enquiry mode, creates the submission only.
 *
 * $req: kind 'room' (room_slug) | 'combo' (venue_slug + rooms [['slug','units'],…]),
 *       check_in, check_out, adults, children, guest_name (the traveller, required),
 *       guest_email, guest_phone, notes.
 *
 * Contact of record = the AGENT. guest_email on the submission and the hold is
 * the agent's login email, so every automatic email (acknowledgement,
 * confirmation, cancellation, expiry, the manage link) and admin's reply reach
 * the trade partner; guest_name is the traveller. The traveller's own contact
 * details go into the payload and the message for reception.
 *
 * Runs in ONE transaction (joins the caller's when one is open, so a test can
 * roll everything back). Never sends email — the caller does, after commit.
 * Returns ['ok'=>true, submission_id, hold_id|null, mode 'hold'|'enquiry', quote,
 * trade, lines, venue, rooms_label, message, hold (joined row)|null, check_in,
 * check_out, adults, children, traveller, notes]
 * or ['ok'=>false, error, code 403|409|422|500].
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
        if (!$room || empty($room['is_published'])) return $err('That room isn’t available to book.');
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
            if (!$room || empty($room['is_published']) || (int)$room['venue_id'] !== (int)$venue['id']) {
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

    $holdMode   = $kind === 'room' && agent_room_form_mode($lines[0]['room']) === 'availability';
    $roomsLabel = implode(', ', array_map(
        fn($l) => $l['room']['name'] . ($l['units'] > 1 ? ' ×' . $l['units'] : ''), $lines));

    // What staff read first — in the notification email and the inbox.
    $msg = ['Trade booking request via the agent portal' . ($holdMode ? '' : ' (enquiry — no hold placed)'),
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

    $pdo   = db();
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();
    $holdId = null;
    try {
        $room = $lines[0]['room'];
        $unit = false;
        if ($holdMode) {
            // Fast pre-check; mi_allocate_and_hold() re-allocates under its own lock.
            $unit = find_available_unit((int)$room['id'], $ci, $co);
            if (!$unit) throw new AgentSoldOutException();
        }

        db_query(
            "INSERT INTO submissions
                (type, room_id, guest_name, guest_email, guest_phone, message,
                 check_in, check_out, guests_adults, guests_children, payload_json,
                 source_page, referrer, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                 user_agent, ip_address)
             VALUES
                ('enquiry', :room_id, :name, :email, :phone, :message,
                 :ci, :co, :adults, :children, :payload,
                 :source_page, :referrer, :utm_source, :utm_medium, :utm_campaign, :utm_term, :utm_content,
                 :ua, :ip)",
            [
                ':room_id'     => $kind === 'room' ? (int)$room['id'] : null,
                ':name'        => $traveller,
                ':email'       => $agentEmail,
                ':phone'       => $tPhone,
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
        $subId = (int)$pdo->lastInsertId();

        if ($holdMode) {
            if (mi_is_composite_room($room)) {
                $holdId = mi_allocate_and_hold($room, $subId, $ci, $co, $traveller, $agentEmail, 'pending', 24);
            } else {
                $holdId = create_hold_with_block((int)$unit['id'], $subId, $ci, $co, $traveller, $agentEmail,
                    'pending', 24, $unit['_mi_components'] ?? null, (int)$room['id']);
            }
            if ($holdId === false) throw new AgentSoldOutException();
            $holdId = (int)$holdId;
            if (holds_agent_supported()) {
                db_query('UPDATE holds SET agent_id = :a WHERE id = :id', [':a' => $agentId, ':id' => $holdId]);
            }
            if (holds_quoted_amount_supported()) {
                db_query('UPDATE holds SET quoted_amount = :q, quoted_currency = :c WHERE id = :id',
                    [':q' => $quote['net'], ':c' => $currency, ':id' => $holdId]);
            }
        }
        if ($ownTx) $pdo->commit();
    } catch (AgentSoldOutException $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        return $err('Those dates were taken while you were completing the request. Please search again.', 409);
    } catch (\Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[agent-request] failed: ' . $e->getMessage());
        return $err('Something went wrong saving the request. Please try again or contact reservations.', 500);
    }

    $hold = $holdId ? db_query(
        "SELECT h.*, u.name AS unit_name, r.name AS room_name
           FROM holds h JOIN units u ON u.id = h.unit_id JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u') . "
          WHERE h.id = :id", [':id' => $holdId]
    )->fetch() : null;

    return [
        'ok' => true, 'submission_id' => $subId, 'hold_id' => $holdId, 'mode' => $holdMode ? 'hold' : 'enquiry',
        'quote' => $quote, 'trade' => $trade, 'lines' => $lines, 'venue' => $venue, 'rooms_label' => $roomsLabel,
        'message' => $message, 'hold' => $hold ?: null, 'check_in' => $ci, 'check_out' => $co,
        'adults' => $adults, 'children' => $children, 'traveller' => $traveller, 'notes' => $notes,
    ];
}

/**
 * The agent's requests, newest first. Every request writes a submission whose
 * payload names the agent, so that is the source of truth — with or without the
 * holds.agent_id column. The latest hold on each submission supplies the status.
 * Each row carries a decoded `payload`.
 */
function agent_requests(array $agent, int $limit = 100): array {
    $aid = (int)($agent['id'] ?? 0);
    if ($aid <= 0) return [];
    $limit = max(1, min(500, $limit));
    $rows = db_query(
        "SELECT s.id, s.created_at, s.check_in, s.check_out, s.guest_name, s.room_id, s.payload_json,
                s.guests_adults, s.guests_children,
                r.name AS room_name, v.name AS venue_name,
                h.id AS hold_id, h.status AS hold_status, h.expires_at, h.access_code
           FROM submissions s
           LEFT JOIN rooms  r ON r.id = s.room_id
           LEFT JOIN venues v ON v.id = r.venue_id
           LEFT JOIN LATERAL (
                SELECT id, status, expires_at, access_code FROM holds
                 WHERE submission_id = s.id ORDER BY id DESC LIMIT 1
           ) h ON TRUE
          WHERE s.payload_json->>'agent_id' = :aid
          ORDER BY s.created_at DESC, s.id DESC
          LIMIT {$limit}",
        [':aid' => (string)$aid]
    )->fetchAll();
    foreach ($rows as $i => $r) {
        $pl = json_decode((string)($r['payload_json'] ?? '{}'), true);
        $rows[$i]['payload'] = is_array($pl) ? $pl : [];
    }
    return $rows;
}

/**
 * After a request is committed: the staff notification — the hold email with the
 * trade rows, or the plain enquiry notification whose stored message already
 * opens with them — and the agent's acknowledgement, addressed to the agent with
 * the net price on record. Best-effort: a mail failure never undoes a saved request.
 */
function agent_send_request_emails(array $agent, array $res): void {
    require_once __DIR__ . '/mail.php';
    $trade = $res['trade'];
    $where = $res['venue']['name'] . ' — ' . $res['rooms_label'];
    try {
        if ($res['mode'] === 'hold' && !empty($res['hold'])) {
            send_hold_notification($res['hold'] + ['trade_agent' => $trade['agent'], 'trade_rate' => $trade['rate']]);
        } else {
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
        }
        send_guest_acknowledgement([
            'kind'            => $res['mode'] === 'hold' ? 'hold' : 'enquiry',
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
            'hold_id'         => (int)($res['hold_id'] ?? 0),
            'access_code'     => (string)($res['hold']['access_code'] ?? ''),
        ]);
    } catch (Throwable $e) {
        error_log('[agent-request] mail failed: ' . $e->getMessage());
    }
}
