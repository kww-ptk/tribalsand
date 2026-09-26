<?php
declare(strict_types=1);
/**
 * POS till — today's sales at an outlet (GET ?outlet=<id>) → {ok, sales[], sums[], can_void},
 * or one sale (GET ?sale=<id>) → {ok, sale}. A sale is readable only when its
 * outlet is one this till may sell at.
 */
require_once __DIR__ . '/../../includes/pos-auth.php';

$ctx = pos_api_guard(false);

if (isset($_GET['sale'])) {
    $s = pos_fetch_sale((int)$_GET['sale']);
    if (!$s || !in_array((int)$s['outlet_id'], $ctx['outlet_ids'], true)) { http_response_code(404); exit(json_encode(['ok' => false, 'error' => 'Sale not found.'])); }
    exit(json_encode(['ok' => true, 'sale' => pos_sale_payload($s), 'can_void' => $s['status'] === 'completed' && pos_user_manages_outlet($ctx['user'], (int)$s['outlet_id'])]));
}

$outlet = pos_ctx_outlet($ctx, (int)($_GET['outlet'] ?? 0));
$today  = frontdesk_today_ymd();
$res    = pos_sales_query(['outlet_ids' => [(int)$outlet['id']], 'from' => $today, 'to' => $today], 100);
echo json_encode([
    'ok'       => true,
    'date'     => $today,
    'sales'    => array_map(fn($s) => [
        'id' => (int)$s['id'], 'reference' => (string)$s['reference'], 'time' => date('H:i', strtotime((string)$s['created_at'])),
        'customer' => (string)$s['customer_name'], 'staff' => (string)($s['user_name'] ?? ''),
        'payment_label' => POS_PAYMENT_METHODS[$s['payment_method']] ?? $s['payment_method'],
        'total' => (float)$s['total'], 'currency' => (string)$s['currency'], 'status' => (string)$s['status'],
    ], $res['rows']),
    'sums'     => array_map(fn($x) => ['currency' => $x['currency'], 'count' => (int)$x['n'], 'total' => (float)$x['total']], $res['sums']),
    'can_void' => pos_user_manages_outlet($ctx['user'], (int)$outlet['id']),
]);
