<?php
declare(strict_types=1);
/**
 * POS till — email a sale's receipt (POST JSON {sale_id, email, csrf_token}) → {ok, sent_to, remaining}.
 * Same guard as the other till endpoints; the sale must belong to an outlet this till
 * serves. Voided sales are not sent. At most POS_RECEIPT_EMAIL_MAX copies per sale.
 */
require_once __DIR__ . '/../../includes/pos-auth.php';
require_once __DIR__ . '/../../includes/mail.php';

$ctx = pos_api_guard(true);
if (!pos_receipt_email_supported()) { http_response_code(503); exit(json_encode(['ok' => false, 'error' => 'Email receipts are not set up yet.'])); }
$sid = (int)($ctx['body']['sale_id'] ?? 0);
$s   = pos_fetch_sale($sid);
if (!$s || !in_array((int)$s['outlet_id'], $ctx['outlet_ids'], true)) { http_response_code(404); exit(json_encode(['ok' => false, 'error' => 'Sale not found.'])); }
if ($s['status'] !== 'completed') { http_response_code(422); exit(json_encode(['ok' => false, 'error' => 'A voided sale has no receipt to send.'])); }
$to = strtolower(trim((string)($ctx['body']['email'] ?? '')));
if (!filter_var($to, FILTER_VALIDATE_EMAIL) || mb_strlen($to) > 160) { http_response_code(422); exit(json_encode(['ok' => false, 'error' => 'Check the email address.'])); }
if ((int)$s['receipt_sent_count'] >= POS_RECEIPT_EMAIL_MAX) { http_response_code(429); exit(json_encode(['ok' => false, 'error' => 'This receipt has already been emailed ' . POS_RECEIPT_EMAIL_MAX . ' times.'])); }

// Count the send first (atomically, capped) so two taps can't both slip under the limit.
$ok = db_query('UPDATE pos_sales SET receipt_sent_count = receipt_sent_count + 1, receipt_email = :e WHERE id = :id AND receipt_sent_count < :max',
    [':e' => $to, ':id' => $sid, ':max' => POS_RECEIPT_EMAIL_MAX])->rowCount() === 1;
if (!$ok) { http_response_code(429); exit(json_encode(['ok' => false, 'error' => 'This receipt has already been emailed ' . POS_RECEIPT_EMAIL_MAX . ' times.'])); }
if (!send_pos_receipt($s, $to)) {
    db_query('UPDATE pos_sales SET receipt_sent_count = GREATEST(receipt_sent_count - 1, 0) WHERE id = :id', [':id' => $sid]);
    http_response_code(502); exit(json_encode(['ok' => false, 'error' => 'The email could not be sent — try again, or print the receipt.']));
}
try { audit_log('pos.receipt_email', 'pos_sale', $sid, $to); } catch (Throwable $e) {}
echo json_encode(['ok' => true, 'sent_to' => $to, 'remaining' => POS_RECEIPT_EMAIL_MAX - (int)$s['receipt_sent_count'] - 1]);
