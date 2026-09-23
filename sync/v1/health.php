<?php
declare(strict_types=1);
/**
 * GET /sync/v1/health — queue depth and last-sync snapshot (§9).
 *
 * Authenticated like every sync endpoint (HMAC over the empty body + peer IP
 * allowlist): queue depth is internal operational data, and the dashboard on
 * either side reads its peer's /health with a signed request. This is NOT the
 * ECS liveness probe — that stays at /health (health.php at web root).
 */
require_once __DIR__ . '/../../includes/sync-api.php';

sync_api_begin();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    sync_api_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

sync_api_authenticate();   // HMAC over the (empty) body

// ?checksums=1 adds per-entity {count, checksum} under `entities` — the same
// flag and shape as Zuri's /health, left out of the default (cheap) report.
$withChecksums = isset($_GET['checksums']) && $_GET['checksums'] !== '0';
if ($withChecksums) require_once __DIR__ . '/../../includes/sync-monitor.php';
sync_api_json(sync_health($withChecksums));
