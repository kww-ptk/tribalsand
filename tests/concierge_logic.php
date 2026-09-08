<?php
declare(strict_types=1);
/**
 * Guest concierge tests (Phase 3). Run: php tests/concierge_logic.php
 *
 * Pure logic (support guards, the guest system prompt, session Turnstile stamp)
 * is asserted directly. The DB-backed rate-limit + logging round-trip runs
 * against the live concierge_log table inside a transaction that is always
 * rolled back. No model or network call is exercised.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/assistant-tools.php';
require_once __DIR__ . '/../includes/concierge.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Support guards ───────────────────────────────────────────────────────────
check('concierge_supported returns bool',      is_bool(concierge_supported()));
check('concierge_log_supported returns bool',   is_bool(concierge_log_supported()));

// ── Guest vs staff system prompt ─────────────────────────────────────────────
$guest = assistant_system_prompt(null, true, 'guest');
$staff = assistant_system_prompt(null, true, 'staff');
check('guest prompt names concierge',           stripos($guest, 'concierge') !== false);
check('guest prompt hands off to Request to Book', stripos($guest, 'Request to Book') !== false);
check('guest prompt forbids claiming a booking', stripos($guest, 'never say you have booked') !== false);
check('guest prompt stays on-topic guard',       stripos($guest, 'Only discuss') !== false);
check('guest differs from staff prompt',         $guest !== $staff);
check('staff prompt is staff-framed',            stripos($staff, 'front-desk and management staff') !== false);
check('guest prompt keeps one-price rule',       stripos($guest, 'Prices come only from the tools') !== false);
check('guest prompt keeps rag rule',             stripos($guest, 'search_property_info') !== false);
check('guest prompt anchors today',              str_contains($guest, assistant_today_ymd()));

// ── Turnstile session stamp ──────────────────────────────────────────────────
$_SESSION = [];
check('unverified session → false',             concierge_session_verified() === false);
concierge_mark_verified();
check('after mark → verified',                  concierge_session_verified() === true);
$_SESSION['concierge_verified_at'] = time() - CONCIERGE_TURNSTILE_TTL - 5;
check('stale stamp → expired',                  concierge_session_verified() === false);
$_SESSION = [];

// ── Rate limit + logging (rolled back) ───────────────────────────────────────
if (!concierge_log_supported()) {
    echo "\nSKIP  concierge_log round-trip (table missing — run add_concierge_log.sql)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
    exit($failures ? 1 : 0);
}
try {
    db()->beginTransaction();
} catch (\Throwable $e) {
    echo "\nSKIP  concierge_log round-trip (database unavailable: " . $e->getMessage() . ")\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
    exit($failures ? 1 : 0);
}
try {
    $ip = '203.0.113.77';   // TEST-NET-3, will never collide with a real client
    check('fresh IP is not rate-limited',       concierge_rate_limited($ip, 3, 10) === false);

    concierge_log_turn($ip, 'sess-abc', 'What is Zuri like?', 'Zuri is a boutique hotel…',
                       [['name' => 'search_property_info']], true);
    $rowcount = (int)db_query("SELECT COUNT(*) FROM concierge_log WHERE client_ip = :ip", [':ip' => $ip])->fetchColumn();
    check('log_turn inserts one row',           $rowcount === 1);
    $row = db_query("SELECT tools_used, tool_count, ok FROM concierge_log WHERE client_ip = :ip", [':ip' => $ip])->fetch();
    check('log records tool name',              (string)$row['tools_used'] === 'search_property_info');
    check('log records tool count',             (int)$row['tool_count'] === 1);
    check('log records ok flag',                (bool)$row['ok'] === true);

    check('under cap → not limited',            concierge_rate_limited($ip, 3, 10) === false);
    concierge_log_turn($ip, 'sess-abc', 'q2', 'a2', [], true);
    concierge_log_turn($ip, 'sess-abc', 'q3', 'a3', [], true);
    check('at cap (3 rows) → limited',          concierge_rate_limited($ip, 3, 10) === true);
    check('a different IP is unaffected',       concierge_rate_limited('198.51.100.9', 3, 10) === false);
} finally {
    db()->rollBack();
}
$leaked = (int)db_query("SELECT COUNT(*) FROM concierge_log WHERE client_ip = '203.0.113.77'")->fetchColumn();
check('round-trip left no rows behind',         $leaked === 0);

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
