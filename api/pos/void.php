<?php
declare(strict_types=1);
/**
 * POS till — void a sale (POST JSON {sale_id, reason, csrf_token}) → {ok, sale}.
 * pos_void_sale() re-checks that the person at the till manages the sale's outlet
 * and requires a reason; it restocks and removes any room-charge bill line.
 */
require_once __DIR__ . '/../../includes/pos-auth.php';

$ctx = pos_api_guard(true);
$sid = (int)($ctx['body']['sale_id'] ?? 0);
$s   = pos_fetch_sale($sid);
if (!$s || !in_array((int)$s['outlet_id'], $ctx['outlet_ids'], true)) { http_response_code(404); exit(json_encode(['ok' => false, 'error' => 'Sale not found.'])); }

$r = pos_void_sale($sid, (string)($ctx['body']['reason'] ?? ''), (int)$ctx['user']['id']);
if (!$r['ok']) { http_response_code(422); exit(json_encode(['ok' => false, 'error' => $r['error']])); }
echo json_encode(['ok' => true, 'sale' => pos_sale_payload($r['sale'])]);
