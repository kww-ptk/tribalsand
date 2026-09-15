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
