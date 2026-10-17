<?php
declare(strict_types=1);
/**
 * POS till — pay by Paystack (a payment link / QR the guest scans).
 *  POST {action:'create', …the same body as api/pos/sale.php}  → {ok, link}
 *       The sale is priced by the real sale path (rolled back) and NOTHING is sold
 *       until Paystack confirms; the same client_uuid returns the same link.
 *  GET  ?id=<link id>                                          → {ok, link, sale?}
 *       Asks Paystack (rate-limited per link); once paid the sale is completed
 *       server-side and returned for the receipt.
 *  POST {action:'cancel', id}                                  → {ok}
 * Every call re-checks that this till may sell at the link's outlet.
 */
require_once __DIR__ . '/../../includes/pos-auth.php';
require_once __DIR__ . '/../../includes/pay-links.php';

$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$ctx  = pos_api_guard($post);
if (!paystack_supported()) { http_response_code(503); exit(json_encode(['ok' => false, 'error' => 'Paystack is not set up yet.'])); }

$out = function (array $l): array {
    return [
        'id' => (int)$l['id'], 'number' => pay_link_number((int)$l['id']), 'url' => pay_link_url((string)$l['token']),
        'status' => pay_link_effective_status($l), 'mode' => (string)$l['mode'],
        'amount' => (float)$l['amount'], 'currency' => (string)$l['currency'], 'label' => pay_link_money($l['amount'], (string)$l['currency']),
        'description' => (string)$l['description'], 'note' => (string)$l['note'], 'started' => !empty($l['started_at']),
        'share_text' => pay_link_share_text($l, (string)$l['company_name']),
    ];
};
$own = function (int $id) use ($ctx): array {
    $l = pay_link_fetch($id);
    if (!$l || $l['purpose'] !== 'pos' || !in_array((int)$l['outlet_id'], $ctx['outlet_ids'], true)) {
        http_response_code(404); exit(json_encode(['ok' => false, 'error' => 'That payment link was not found.']));
    }
    return $l;
};

try {
    if (!$post) {
        $l = $own((int)($_GET['id'] ?? 0));
        $l = pay_link_check((int)$l['id']) ?: $l;
        if ($l['status'] === 'paid' && !$l['pos_sale_id'] && trim((string)$l['note']) === '') {
            pay_link_complete_pos((int)$l['id']);   // paid by another path a moment ago: finish the sale now
            $l = pay_link_fetch((int)$l['id']);
        }
        $sale = $l['pos_sale_id'] ? pos_fetch_sale((int)$l['pos_sale_id']) : false;
        exit(json_encode(['ok' => true, 'link' => $out($l), 'sale' => $sale ? pos_sale_payload($sale) : null]));
    }

    $body = $ctx['body'];
    $act  = (string)($body['action'] ?? '');
    if ($act === 'cancel') {
        $l = $own((int)($body['id'] ?? 0));
        pay_link_cancel((int)$l['id'], (int)$ctx['user']['id']);
        exit(json_encode(['ok' => true]));
    }
    if ($act !== 'create') { http_response_code(400); exit(json_encode(['ok' => false, 'error' => 'Unknown action.'])); }

    pos_ctx_outlet($ctx, (int)($body['outlet_id'] ?? 0));
    $l = pay_link_create_pos([
        'outlet_id'   => (int)($body['outlet_id'] ?? 0),
        'client_uuid' => (string)($body['client_uuid'] ?? ''),
        'lines'       => array_map(fn($x) => [
                            'item_id' => (int)($x['item_id'] ?? 0), 'qty' => (int)($x['qty'] ?? 0), 'open_price' => $x['open_price'] ?? null,
                         ], array_values(array_filter((array)($body['lines'] ?? []), 'is_array'))),
        'customer'    => is_array($body['customer'] ?? null) ? $body['customer'] : [],
        'tip_pct'     => $body['tip_pct'] ?? null,
        'tip_amount'  => $body['tip_amount'] ?? null,
    ], (int)$ctx['user']['id'], $ctx['terminal'] ? (int)$ctx['terminal']['id'] : null);
    exit(json_encode(['ok' => true, 'link' => $out($l)]));
} catch (PayLinkRefusal $e) {
    http_response_code(422);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
} catch (Throwable $e) {
    error_log('[pos paylink] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'The payment link could not be made — try again.']));
}
