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
