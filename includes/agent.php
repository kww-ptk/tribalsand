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
    $rate = $pct > 0
        ? format_price((float)($quote['net'] ?? 0), $cur) . ' net · ' . $nightsTxt . ' · '
          . agent_pct_label($pct) . '% off published ' . format_price((float)($quote['published'] ?? 0), $cur)
        : format_price((float)($quote['published'] ?? 0), $cur) . ' · ' . $nightsTxt . ' · published rate (no trade discount)';
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
