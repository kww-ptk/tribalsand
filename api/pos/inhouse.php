<?php
declare(strict_types=1);
/**
 * POS till — in-house guests for an outlet (GET ?outlet=<id>&q=<text>) →
 * {ok, results:[{hold_id,name,room,dates,ref,guests[],room_charge_block}]}.
 * A venue-bound outlet sees only its property's guests; a shared outlet sees all.
 * room_charge_block is null when the booking can take a room charge, else the reason.
 */
require_once __DIR__ . '/../../includes/pos-auth.php';

$ctx    = pos_api_guard(false);
$outlet = pos_ctx_outlet($ctx, (int)($_GET['outlet'] ?? 0));
$q      = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
echo json_encode(['ok' => true, 'results' => pos_inhouse_payload($outlet, $q)]);
