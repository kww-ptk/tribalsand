<?php
declare(strict_types=1);
/**
 * Guest concierge helpers (Phase 3) — the public-facing side of the AI
 * assistant. Same read-only tool+RAG engine as the admin assistant, but exposed
 * to website visitors, so it carries the public-endpoint guardrails: Turnstile
 * (fail-closed), IP rate limiting, CSRF (all enforced in api/concierge.php).
 *
 * This file holds only the concierge-specific bits: the support/log guards and
 * the rate-limit + logging backed by the concierge_log table. The prompt, tools
 * and loop are shared with the admin assistant (assistant-tools.php / ai.php) —
 * the concierge just calls assistant_system_prompt(..., 'guest') and runs the
 * same tools with a null (all published venues) scope.
 *
 * Read-only end to end: the concierge can quote and describe, never book. When a
 * guest wants to book, the UI hands off to the existing "Request to Book" flow.
 */
require_once __DIR__ . '/db.php';             // db_query(), client_ip(), to_regclass guard pattern
require_once __DIR__ . '/ai.php';             // ai_assistant_supported()
require_once __DIR__ . '/assistant-tools.php';// shared prompt + tools
require_once __DIR__ . '/assistant-rag.php';  // rag_supported()

const CONCIERGE_RATE_MAX      = 25;   // max answered turns per IP per window
const CONCIERGE_RATE_WINDOW   = 10;   // minutes
const CONCIERGE_TURNSTILE_TTL = 1800; // seconds a passed Turnstile check covers a session (re-verify after)

/** Feature usable at all? Same key gate as the admin assistant. */
function concierge_supported(): bool {
    return ai_assistant_supported();
}

/** Is the log/rate-limit table present? Memoized. Pre-migration-safe. */
function concierge_log_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.concierge_log')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/**
 * True when this IP has already used up its allowance in the current window.
 * Mirrors the reservation/enquiry endpoints (count rows by client_ip). Fails
 * OPEN if the table/read is unavailable — a logging outage must not lock guests
 * out (the other guardrails still apply).
 */
function concierge_rate_limited(string $ip, int $max = CONCIERGE_RATE_MAX, int $windowMin = CONCIERGE_RATE_WINDOW): bool {
    if ($ip === '' || !concierge_log_supported()) return false;
    try {
        $n = (int) db_query(
            "SELECT COUNT(*) FROM concierge_log
              WHERE client_ip = :ip AND created_at > now() - (:mins || ' minutes')::interval",
            [':ip' => $ip, ':mins' => (string)$windowMin]
        )->fetchColumn();
        return $n >= $max;
    } catch (Throwable $e) {
        return false;   // never block on a rate-limit read failure
    }
}

/**
 * Record one answered turn — rate-limit fuel + observability (NFR7). Best-effort:
 * a logging failure never breaks the reply. $tools is the list from a
 * chat_with_tools() result (['name'=>…], …).
 */
function concierge_log_turn(string $ip, string $sessionId, string $question, string $answer, array $tools, bool $ok): void {
    if (!concierge_log_supported()) return;
    $names = [];
    foreach ($tools as $t) { $n = trim((string)($t['name'] ?? '')); if ($n !== '') $names[] = $n; }
    try {
        db_query(
            'INSERT INTO concierge_log (client_ip, session_id, question, answer, tools_used, tool_count, ok)
             VALUES (:ip, :sid, :q, :a, :tu, :tc, :ok)',
            [
                ':ip'  => mb_substr($ip, 0, 64),
                ':sid' => mb_substr($sessionId, 0, 64),
                ':q'   => mb_substr($question, 0, 2000),
                ':a'   => mb_substr($answer, 0, 4000),
                ':tu'  => mb_substr(implode(',', $names), 0, 255),
                ':tc'  => count($names),
                ':ok'  => $ok,
            ]
        );
    } catch (Throwable $e) {
        error_log('[concierge] log insert failed: ' . $e->getMessage());
    }
}

/**
 * Has this session already passed a Turnstile check recently? Lets the widget
 * ask for the challenge only on the FIRST message of a session (good chat UX),
 * then trust it for CONCIERGE_TURNSTILE_TTL seconds. The caller sets the stamp
 * with concierge_mark_verified() after a successful verify_captcha().
 */
function concierge_session_verified(): bool {
    $ts = (int)($_SESSION['concierge_verified_at'] ?? 0);
    return $ts > 0 && (time() - $ts) < CONCIERGE_TURNSTILE_TTL;
}

/** Stamp the session as human-verified (after a passing Turnstile check). */
function concierge_mark_verified(): void {
    $_SESSION['concierge_verified_at'] = time();
}
