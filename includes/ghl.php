<?php
declare(strict_types=1);
// db.php is required only for parse_env() (env/.env reader); ghl.php itself opens no DB connection.
require_once __DIR__ . '/db.php';

const GHL_BASE    = 'https://services.leadconnectorhq.com';
const GHL_VERSION = '2021-07-28';

/** Low-level GHL API call. Returns ['ok'=>bool,'status'=>int,'data'=>array]. */
function ghl_request(string $method, string $path, array $body = []): array {
    $key = parse_env()['GHL_API_KEY'] ?? '';
    if (!$key) return ['ok' => false, 'status' => 0, 'data' => [], 'skipped' => true];

    $ch = curl_init(GHL_BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
            'Version: ' . GHL_VERSION,
        ],
    ]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    if ($method === 'PUT')  { curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT'); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    @curl_close($ch);
    if ($err) return ['ok' => false, 'status' => 0, 'data' => [], 'error' => $err];
    return ['ok' => $code < 300, 'status' => $code, 'data' => json_decode($resp, true) ?? []];
}

/**
 * Push a normalized lead to GHL: upsert contact, create opportunity, post a note.
 * $lead keys: firstName,lastName,email,phone,country,property,arrival,departure,
 *             adults,children,nights,message,source,tags(array),note
 * Returns ['ok'=>bool,'contactId'=>?string]. Never throws; logs nothing fatal.
 */
function ghl_push(array $lead): array {
    $env = parse_env();
    $key = $env['GHL_API_KEY'] ?? '';
    if (!$key) return ['ok' => false, 'skipped' => true];

    $loc      = $env['GHL_LOCATION_ID'] ?? '';
    $pipeline = $env['GHL_PIPELINE_ID'] ?? '';
    $stage    = $env['GHL_STAGE_ID'] ?? '';

    $cf = [];
    $map = [
        'property'        => $lead['property']  ?? '',
        'arrivaldate'     => $lead['arrival']   ?? '',
        'departuredate'   => $lead['departure'] ?? '',
        'adults'          => (string)($lead['adults']   ?? ''),
        'children'        => (string)($lead['children'] ?? ''),
        'nights'          => (string)($lead['nights']   ?? ''),
        'enquiry_message' => $lead['message']   ?? '',
    ];
    foreach ($map as $k => $v) { if ($v !== '' && $v !== null) $cf[] = ['key' => $k, 'field_value' => (string)$v]; }

    $contactBody = [
        'locationId' => $loc,
        'firstName'  => $lead['firstName'] ?? '',
        'lastName'   => $lead['lastName']  ?? '',
        'email'      => $lead['email']     ?? '',
        'phone'      => $lead['phone']     ?? '',
        'source'     => 'tribalsand.com',
        'tags'       => $lead['tags']      ?? ['website-enquiry'],
    ];
    if ($cf) $contactBody['customFields'] = $cf;

    $res = ghl_request('POST', '/contacts/', $contactBody);
    $contactId = $res['data']['contact']['id'] ?? $res['data']['meta']['contactId'] ?? null;
    if (!$contactId) {
        error_log('[ghl_push] contact upsert failed: ' . json_encode($res));
        return ['ok' => false];
    }

    if ($pipeline && $stage) {
        $oppRes = ghl_request('POST', '/opportunities/', [
            'locationId'      => $loc,
            'pipelineId'      => $pipeline,
            'pipelineStageId' => $stage,
            'contactId'       => $contactId,
            'name'            => trim(($lead['firstName'] ?? '') . ' ' . ($lead['lastName'] ?? '')) . ' · ' . ($lead['property'] ?? ''),
            'status'          => 'open',
            'source'          => $lead['source'] ?? 'Website Enquiry',
        ]);
        if (!$oppRes['ok']) error_log('[ghl_push] opportunity create failed: ' . json_encode($oppRes));
    }
    if (!empty($lead['note'])) {
        $noteRes = ghl_request('POST', '/conversations/messages', [
            'locationId' => $loc, 'contactId' => $contactId, 'type' => 'Note', 'message' => $lead['note'],
        ]);
        if (!$noteRes['ok']) error_log('[ghl_push] note post failed: ' . json_encode($noteRes));
    }
    return ['ok' => true, 'contactId' => $contactId];
}
