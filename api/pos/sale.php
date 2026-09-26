<?php
declare(strict_types=1);
/**
 * POS till — complete a sale (POST JSON, csrf_token in the body) → {ok, sale, duplicate}.
 * Body: {outlet_id, client_uuid, lines:[{item_id,qty,open_price?}], payment_method,
 *        payment_ref?, cash_tendered?, customer:{type:'inhouse',hold_id,guest_id?}|{type:'walkin',name?,phone?,pos_customer_id?}}
 * The outlet must be one this till may sell at (user ∩ terminal); everything else
 * — prices, stock, room-charge eligibility — is re-decided by pos_complete_sale().
 */
require_once __DIR__ . '/../../includes/pos-auth.php';

$ctx  = pos_api_guard(true);
$body = $ctx['body'];
pos_ctx_outlet($ctx, (int)($body['outlet_id'] ?? 0));

$r = pos_complete_sale([
    'outlet_id'      => (int)($body['outlet_id'] ?? 0),
    'client_uuid'    => (string)($body['client_uuid'] ?? ''),
    'lines'          => array_map(fn($l) => [
                            'item_id'    => (int)($l['item_id'] ?? 0),
                            'qty'        => (int)($l['qty'] ?? 0),
                            'open_price' => $l['open_price'] ?? null,
                        ], array_values(array_filter((array)($body['lines'] ?? []), 'is_array'))),
    'payment_method' => (string)($body['payment_method'] ?? ''),
    'payment_ref'    => (string)($body['payment_ref'] ?? ''),
    'cash_tendered'  => $body['cash_tendered'] ?? null,
    'customer'       => is_array($body['customer'] ?? null) ? $body['customer'] : [],
], (int)$ctx['user']['id'], $ctx['terminal'] ? (int)$ctx['terminal']['id'] : null);

if (!$r['ok']) { http_response_code(422); exit(json_encode(['ok' => false, 'error' => $r['error']])); }
echo json_encode(['ok' => true, 'duplicate' => $r['duplicate'], 'sale' => pos_sale_payload($r['sale'])]);
