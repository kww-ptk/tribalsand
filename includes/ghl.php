<?php
declare(strict_types=1);
/**
 * GoHighLevel (LeadConnector) — the ONE server-side path from our lead forms to
 * the GHL CRM.
 *
 *   ghl_push($lead)              upsert contact → opportunity → conversation note.
 *   ghl_push_submission($id)     build a lead from a saved `submissions` row and
 *                                push it; remembers the GHL contact id on the row
 *                                (payload_json.ghl_contact_id) so an inbound
 *                                WhatsApp reply can be matched back to it
 *                                (api/ghl-webhook.php).
 *   ghl_forward_webhook($body)   forward a form's payload to the GHL inbound
 *                                webhook trigger (the Trip Builder / waitlist
 *                                workflows) from the SERVER, after Turnstile +
 *                                rate limit — never from the browser again.
 *
 * Every public form saves its `submissions` row and sends our own emails FIRST;
 * GHL is best-effort CRM sync afterwards. A GHL outage or a missing key never
 * loses a lead or fails the guest's request. Auth: GHL_API_KEY is a Private
 * Integration Token (Authorization: Bearer + Version header).
 */
// db.php is required for parse_env() + db_query(); ghl_request() itself opens no DB connection.
require_once __DIR__ . '/db.php';

const GHL_BASE    = 'https://services.leadconnectorhq.com';
const GHL_VERSION = '2021-07-28';
/** The inbound-webhook trigger the Trip Builder / waitlist workflows were built on. Override with GHL_WEBHOOK_URL. */
const GHL_DEFAULT_WEBHOOK_URL = 'https://services.leadconnectorhq.com/hooks/cBTrngnK5Q4lTkFUwhlo/webhook-trigger/ad7f1a2d-9c2a-4f9a-9049-c30b144643e5';

/** True when the CRM API is configured (a Private Integration Token is set). */
function ghl_configured(): bool {
    return (string)(parse_env()['GHL_API_KEY'] ?? '') !== '';
}

/**
 * Low-level GHL API call. Returns ['ok'=>bool,'status'=>int,'data'=>array].
 * function_exists-guarded so tests can stub the network (tests/ghl_logic.php).
 */
if (!function_exists('ghl_request')) {
function ghl_request(string $method, string $path, array $body = []): array {
    $key = parse_env()['GHL_API_KEY'] ?? '';
    if (!$key) return ['ok' => false, 'status' => 0, 'data' => [], 'skipped' => true];

    $ch = curl_init(GHL_BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
            'Accept: application/json',
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
    return ['ok' => $code < 300, 'status' => $code, 'data' => json_decode((string)$resp, true) ?? []];
}
}

/**
 * The contact custom fields for a lead. PURE + testable.
 * Keys are the exact GHL field keys (Settings → Custom Fields → Contacts);
 * 'enquiry_souce' carries GHL's own typo and must stay spelled that way.
 * $lead['customFields'] (key => value) adds/overrides extra keys.
 */
function ghl_custom_fields(array $lead): array {
    $map = [
        'property'        => $lead['property']  ?? '',
        'arrivaldate'     => $lead['arrival']   ?? '',
        'departuredate'   => $lead['departure'] ?? '',
        'adults'          => (string)($lead['adults']   ?? ''),
        'children'        => (string)($lead['children'] ?? ''),
        'nights'          => (string)($lead['nights']   ?? ''),
        'enquiry_message' => $lead['message']   ?? '',
        'enquiry_souce'   => $lead['source']    ?? '',
    ];
    foreach (($lead['customFields'] ?? []) as $k => $v) $map[(string)$k] = is_scalar($v) ? (string)$v : json_encode($v);
    $cf = [];
    foreach ($map as $k => $v) { if ($v !== '' && $v !== null) $cf[] = ['key' => $k, 'field_value' => (string)$v]; }
    return $cf;
}

/**
 * Push a normalized lead to GHL: upsert contact, create opportunity, post a note.
 * $lead keys: firstName,lastName,email,phone,country,property,arrival,departure,
 *             adults,children,nights,message,source,tags(array),note,
 *             opportunityName, monetaryValue, customFields(assoc)
 * Returns ['ok'=>bool,'contactId'=>?string,'skipped'=>bool]. Never throws.
 */
function ghl_push(array $lead): array {
    $env = parse_env();
    if (empty($env['GHL_API_KEY'])) return ['ok' => false, 'skipped' => true, 'contactId' => null];

    $loc      = $env['GHL_LOCATION_ID'] ?? '';
    $pipeline = $env['GHL_PIPELINE_ID'] ?? '';
    $stage    = $env['GHL_STAGE_ID'] ?? '';
    $tags     = array_values(array_filter(array_map('strval', $lead['tags'] ?? ['website-enquiry'])));
    $cf       = ghl_custom_fields($lead);

    $contactBody = [
        'locationId' => $loc,
        'firstName'  => $lead['firstName'] ?? '',
        'lastName'   => $lead['lastName']  ?? '',
        'email'      => $lead['email']     ?? '',
        'phone'      => $lead['phone']     ?? '',
        'source'     => 'tribalsand.com',
        'tags'       => $tags,
    ];
    if (!empty($lead['country'])) $contactBody['country'] = (string)$lead['country'];
    if ($cf) $contactBody['customFields'] = $cf;

    try {
        $res = ghl_request('POST', '/contacts/', $contactBody);
        // A duplicate (same email/phone) answers 400 with the EXISTING id in meta.
        $contactId   = $res['data']['contact']['id'] ?? $res['data']['meta']['contactId'] ?? null;
        $isDuplicate = !$res['ok'] && $contactId;
        if (!$contactId) {
            error_log('[ghl_push] contact upsert failed: ' . json_encode($res));
            return ['ok' => false, 'skipped' => false, 'contactId' => null];
        }
        // Existing contact: still record this enquiry's tags + fields on it.
        if ($isDuplicate) {
            $put = ['tags' => $tags, 'source' => 'tribalsand.com'];
            if ($cf) $put['customFields'] = $cf;
            $putRes = ghl_request('PUT', '/contacts/' . $contactId, $put);
            if (!$putRes['ok']) error_log('[ghl_push] duplicate contact update failed: ' . json_encode($putRes));
        }

        if ($pipeline && $stage) {
            $name = trim((string)($lead['opportunityName'] ?? ''));
            if ($name === '') {
                $name = trim(($lead['firstName'] ?? '') . ' ' . ($lead['lastName'] ?? ''))
                      . (!empty($lead['property']) ? ' · ' . $lead['property'] : '') . ' · ' . date('d M Y');
            }
            $oppRes = ghl_request('POST', '/opportunities/', [
                'locationId'      => $loc,
                'pipelineId'      => $pipeline,
                'pipelineStageId' => $stage,
                'contactId'       => $contactId,
                'name'            => $name,
                'status'          => 'open',
                'monetaryValue'   => (float)($lead['monetaryValue'] ?? 0),
                'source'          => $lead['source'] ?? 'Website Enquiry',
            ]);
            if (!$oppRes['ok']) error_log('[ghl_push] opportunity create failed: ' . json_encode($oppRes));
        }
        if (!empty($lead['note'])) {
            $noteRes = ghl_request('POST', '/conversations/messages', [
                'locationId' => $loc, 'contactId' => $contactId, 'type' => 'Note', 'message' => (string)$lead['note'],
            ]);
            if (!$noteRes['ok']) error_log('[ghl_push] note post failed: ' . json_encode($noteRes));
        }
        return ['ok' => true, 'skipped' => false, 'contactId' => (string)$contactId];
    } catch (Throwable $e) {
        error_log('[ghl_push] ' . $e->getMessage());
        return ['ok' => false, 'skipped' => false, 'contactId' => null];
    }
}

/** Split "Mary Jane Otieno" → ['Mary Jane', 'Otieno']. PURE. */
function ghl_split_name(string $full): array {
    $full = trim((string)preg_replace('/\s+/u', ' ', $full));
    if ($full === '') return ['', ''];
    $pos = mb_strrpos($full, ' ');
    return $pos === false ? [$full, ''] : [mb_substr($full, 0, $pos), mb_substr($full, $pos + 1)];
}

/** Default tags + opportunity source per submission type. PURE. */
function ghl_type_meta(string $type): array {
    return match ($type) {
        'enquiry'      => ['tags' => ['website-enquiry'],  'source' => 'Website Enquiry'],
        'availability' => ['tags' => ['website-enquiry', 'availability-search'], 'source' => 'Website Availability Search'],
        'contact'      => ['tags' => ['website-contact'],  'source' => 'Website Contact'],
        'agency'       => ['tags' => ['trade-agency'],     'source' => 'Travel Agency Enquiry'],
        'trip_builder' => ['tags' => ['trip-enquiry'],     'source' => 'Trip Builder'],
        'event'        => ['tags' => ['event-enquiry'],    'source' => 'Event Enquiry'],
        default        => ['tags' => ['website-enquiry'],  'source' => 'Website'],
    };
}

/**
 * Build the GHL lead from a saved submissions row. PURE + testable.
 * $row may carry the joined room_name / venue_name / tour_name.
 */
function ghl_lead_from_submission(array $row): array {
    $payload = is_array($row['payload_json'] ?? null) ? $row['payload_json'] : (json_decode((string)($row['payload_json'] ?? ''), true) ?: []);
    [$first, $last] = ghl_split_name((string)($row['guest_name'] ?? ''));
    $meta = ghl_type_meta((string)($row['type'] ?? ''));

    $property = trim((string)($row['room_name'] ?? ''));
    if ($property !== '' && !empty($row['venue_name']) && stripos($property, (string)$row['venue_name']) === false) {
        $property = $row['venue_name'] . ' · ' . $property;
    }
    if ($property === '' && !empty($row['tour_name'])) $property = 'Tour: ' . $row['tour_name'];
    if ($property === '' && !empty($payload['property']) && is_string($payload['property'])) $property = $payload['property'];
    if ($property === '' && !empty($payload['agency_name'])) $property = 'Agency: ' . $payload['agency_name'];

    $ci = (string)($row['check_in'] ?? '');
    $co = (string)($row['check_out'] ?? '');
    $nights = ($ci !== '' && $co !== '' && strtotime($co) > strtotime($ci)) ? (int)round((strtotime($co) - strtotime($ci)) / 86400) : '';

    $message = trim((string)($row['message'] ?? ''));
    $id = (int)($row['id'] ?? 0);
    $lines = ['New ' . strtolower($meta['source']) . ' via tribalsand.com' . ($id ? ' (admin #' . $id . ')' : '')];
    if ($property !== '')                 $lines[] = 'Property:   ' . $property;
    if ($ci !== '')                       $lines[] = 'Arrival:    ' . $ci;
    if ($co !== '')                       $lines[] = 'Departure:  ' . $co;
    if ($ci !== '' || !empty($row['guests_adults'])) {
        $lines[] = 'Guests:     ' . (int)($row['guests_adults'] ?? 0) . ' adults'
                 . ((int)($row['guests_children'] ?? 0) > 0 ? ', ' . (int)$row['guests_children'] . ' children' : '');
    }
    if (!empty($payload['subject']))      $lines[] = 'Subject:    ' . $payload['subject'];
    if (!empty($payload['quoted_total'])) $lines[] = 'Quoted:     ' . trim(($payload['quoted_currency'] ?? '') . ' ' . $payload['quoted_total']);
    if (!empty($payload['agency_name']))  $lines[] = 'Agency:     ' . $payload['agency_name'] . (!empty($payload['iata']) ? ' (IATA ' . $payload['iata'] . ')' : '');
    if ($message !== '')                  $lines[] = "\nMessage:\n" . $message;

    return [
        'firstName' => $first,
        'lastName'  => $last,
        'email'     => (string)($row['guest_email'] ?? ''),
        'phone'     => (string)($row['guest_phone'] ?? ''),
        'country'   => (string)($payload['country'] ?? ''),
        'property'  => $property,
        'arrival'   => $ci,
        'departure' => $co,
        'adults'    => $row['guests_adults'] ?? '',
        'children'  => $row['guests_children'] ?? '',
        'nights'    => $nights,
        'message'   => $message,
        'source'    => $meta['source'],
        'tags'      => $meta['tags'],
        'note'      => implode("\n", $lines),
        'monetaryValue' => is_numeric($payload['quoted_total'] ?? null) ? (float)$payload['quoted_total'] : 0,
    ];
}

/**
 * Push a saved submission to GHL and remember the contact id on it.
 * $overrides replace keys of the built lead (tags, source, note, customFields …).
 * Best-effort: never throws, no-op (skipped) when GHL_API_KEY is unset.
 */
function ghl_push_submission(int $submissionId, array $overrides = []): array {
    if ($submissionId <= 0 || !ghl_configured()) return ['ok' => false, 'skipped' => true, 'contactId' => null];
    try {
        $row = db_query(
            "SELECT s.*, r.name AS room_name, v.name AS venue_name, t.name AS tour_name
             FROM submissions s
             LEFT JOIN rooms r  ON r.id = s.room_id
             LEFT JOIN venues v ON v.id = r.venue_id
             LEFT JOIN tours t  ON t.id = s.tour_id
             WHERE s.id = :id",
            [':id' => $submissionId]
        )->fetch();
        if (!$row) return ['ok' => false, 'skipped' => false, 'contactId' => null];

        $res = ghl_push(array_merge(ghl_lead_from_submission($row), $overrides));
        if (!empty($res['contactId'])) {
            db_query(
                "UPDATE submissions SET payload_json = COALESCE(payload_json, '{}'::jsonb) || jsonb_build_object('ghl_contact_id', CAST(:c AS text))
                 WHERE id = :id",
                [':c' => $res['contactId'], ':id' => $submissionId]
            );
        }
        return $res;
    } catch (Throwable $e) {
        error_log('[ghl_push_submission] #' . $submissionId . ': ' . $e->getMessage());
        return ['ok' => false, 'skipped' => false, 'contactId' => null];
    }
}

/**
 * Forward a JSON payload to the GHL inbound webhook trigger (server-side).
 * Used by the Trip Builder and the waitlist forms, whose GHL workflows were
 * built on that trigger. Returns true on a 2xx. Never throws.
 */
function ghl_forward_webhook(array $payload): bool {
    $url = trim((string)(parse_env()['GHL_WEBHOOK_URL'] ?? '')) ?: GHL_DEFAULT_WEBHOOK_URL;
    if (strtolower($url) === 'off') return false;   // kill switch (and what tests use)
    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        @curl_close($ch);
        if ($err || $code >= 300) { error_log('[ghl_forward_webhook] failed: ' . ($err ?: 'HTTP ' . $code)); return false; }
        return true;
    } catch (Throwable $e) {
        error_log('[ghl_forward_webhook] ' . $e->getMessage());
        return false;
    }
}

/**
 * Echo a JSON response and release the browser immediately (see
 * ghl_finish_response). Endpoints call this in place of their final
 * `echo json_encode(...)`, then do the CRM sync.
 */
function ghl_respond_json(array $data): void {
    ob_start();
    echo json_encode($data);
    ghl_finish_response();
}

/**
 * Send the JSON response to the browser NOW and keep running, so the guest never
 * waits on GHL. Uses fastcgi_finish_request() where PHP-FPM provides it; under
 * Apache mod_php it closes the connection with Content-Length and flushes. Call
 * AFTER echoing the response body and BEFORE the slow CRM calls.
 */
function ghl_finish_response(): void {
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); return; }
    if (headers_sent() || PHP_SAPI === 'cli') { @flush(); return; }
    $body = '';
    while (ob_get_level() > 0) $body = ob_get_clean() . $body;
    header('Connection: close');
    header('Content-Encoding: none');   // stop mod_deflate buffering the whole response
    header('Content-Length: ' . strlen($body));
    echo $body;
    @flush();
}
