<?php
/**
 * FX rate sync — refreshes the display-currency rate table from open.er-api.com.
 * Protected by FX_SYNC_SECRET env var. Display-only: never touches booking prices.
 *
 * Trigger via external cron (e.g. cron-job.org) once daily:
 *   GET https://yoursite/api/fx-sync.php   with header  Authorization: Bearer YOUR_SECRET
 *   (legacy ?secret=YOUR_SECRET still works for crons that can't set a header)
 *
 * Or from the admin Settings "Sync now" button (which calls fx_sync_rates() directly).
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

$env        = parse_env();
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($authHeader, 'Bearer ')) {
    $secret = trim(substr($authHeader, 7));
} else {
    $secret = trim($_GET['secret'] ?? '');
}
$expected = trim($env['FX_SYNC_SECRET'] ?? '');

if (!$expected || !hash_equals($expected, $secret)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Forbidden — set FX_SYNC_SECRET in environment.']));
}

$result = fx_sync_rates();
echo json_encode(['synced_at' => date('c')] + $result, JSON_PRETTY_PRINT);
