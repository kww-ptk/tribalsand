<?php
declare(strict_types=1);
/**
 * POS till — one outlet's catalogue (GET ?outlet=<id>) → {ok, outlet, categories, sources, items}.
 * Items include the outlets this one cross-sells. Prices are for display only:
 * the sale endpoint re-prices every line server-side.
 */
require_once __DIR__ . '/../../includes/pos-auth.php';

$ctx    = pos_api_guard(false);
$outlet = pos_ctx_outlet($ctx, (int)($_GET['outlet'] ?? 0));
echo json_encode(['ok' => true] + pos_catalog_payload($outlet));
