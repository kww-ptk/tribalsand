<?php
declare(strict_types=1);
/**
 * Submission "smart trends" — read-only aggregation over the submissions inbox
 * so the team can see, at a glance, what kind of enquiries come in most: which
 * property, which requested dates, what party sizes, where the lead came from
 * (source) and whether they travel with children.
 *
 * PURE aggregation: no auth here. The caller passes a venue-scope condition (the
 * same one admin/submissions.php builds from venue_scope_sql) plus a date window,
 * so a scoped account only ever sees its own properties' enquiries. Every read is
 * wrapped so a query error degrades to an empty section rather than fatalling.
 */
require_once __DIR__ . '/db.php';

/** A friendly party-size bucket label for a guest count. PURE + testable. */
function subtrends_party_bucket(int $n): string {
    return match (true) {
        $n <= 0 => 'Not specified',
        $n === 1 => '1 guest',
        $n === 2 => '2 guests',
        $n <= 4 => '3–4 guests',
        $n <= 6 => '5–6 guests',
        default => '7+ guests',
    };
}

/** Fixed display order for the party buckets. */
function subtrends_party_order(): array {
    return ['1 guest', '2 guests', '3–4 guests', '5–6 guests', '7+ guests', 'Not specified'];
}

/** A friendly children-count bucket label. PURE + testable. */
function subtrends_children_bucket(int $n): string {
    return match (true) {
        $n <= 0 => 'No children',
        $n === 1 => '1 child',
        $n === 2 => '2 children',
        default => '3+ children',
    };
}

/** Fixed display order for the children buckets. */
function subtrends_children_order(): array {
    return ['No children', '1 child', '2 children', '3+ children'];
}

/** Hosts that are "us" — a referrer from our own site is not a lead source. */
function subtrends_own_hosts(): array {
    $hosts = ['tribalsand.com', 'localhost', '127.0.0.1'];
    $app = (string)(parse_url((string)(parse_env()['APP_URL'] ?? ''), PHP_URL_HOST) ?? '');
    if ($app !== '') $hosts[] = strtolower($app);
    return $hosts;
}

/**
 * Where a lead came from, as one friendly channel label. PURE + testable.
 * Priority: trade portal (a server-written agent link) → UTM source (what a
 * campaign link says) → the external referrer's site → "Direct / unknown".
 * $ownHosts are our own domains; a referrer from them is internal navigation.
 */
function subtrends_source_label(string $utmSource, string $utmMedium, string $referrer, bool $isAgent, array $ownHosts = []): string {
    if ($isAgent) return 'Travel agent portal';

    $u = strtolower(trim($utmSource));
    $m = strtolower(trim($utmMedium));
    if ($u === 'trade-portal') return 'Travel agent portal';
    if ($u !== '') {
        $paid = in_array($m, ['cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'paid-social', 'paid_social', 'ads'], true);
        return match (true) {
            str_contains($u, 'google')                                           => $paid ? 'Google Ads' : 'Google',
            str_contains($u, 'facebook') || $u === 'fb' || str_contains($u, 'meta') => $paid ? 'Facebook / Instagram ads' : 'Facebook',
            str_contains($u, 'instagram') || $u === 'ig'                         => $paid ? 'Facebook / Instagram ads' : 'Instagram',
            str_contains($u, 'whatsapp')                                         => 'WhatsApp',
            str_contains($u, 'tripadvisor')                                      => 'TripAdvisor',
            str_contains($u, 'mail') || $m === 'email'                           => 'Email campaign',
            default                                                              => 'Campaign: ' . trim($utmSource),
        };
    }

    $host = strtolower((string)(parse_url(trim($referrer), PHP_URL_HOST) ?? ''));
    $host = (string)preg_replace('/^www\./', '', $host);
    if ($host === '') return 'Direct / unknown';
    foreach ($ownHosts as $own) {
        $own = (string)preg_replace('/^www\./', '', strtolower((string)$own));
        if ($own !== '' && ($host === $own || str_ends_with($host, '.' . $own))) return 'Direct / unknown';
    }
    return match (true) {
        (bool)preg_match('/(^|\.)google\./', $host)                                 => 'Google',
        str_contains($host, 'bing.')                                                => 'Bing',
        str_contains($host, 'duckduckgo')                                           => 'DuckDuckGo',
        str_contains($host, 'yahoo.')                                               => 'Yahoo',
        str_contains($host, 'facebook.') || $host === 'fb.com'                      => 'Facebook',
        str_contains($host, 'instagram.')                                           => 'Instagram',
        str_contains($host, 'whatsapp.') || $host === 'wa.me'                       => 'WhatsApp',
        str_contains($host, 'tripadvisor.')                                         => 'TripAdvisor',
        str_contains($host, 'booking.com')                                          => 'Booking.com',
        str_contains($host, 'airbnb.')                                              => 'Airbnb',
        str_contains($host, 'linkedin.')                                            => 'LinkedIn',
        $host === 't.co' || str_contains($host, 'twitter.') || $host === 'x.com'    => 'X / Twitter',
        str_contains($host, 'chatgpt.') || str_contains($host, 'openai.')           => 'ChatGPT',
        default                                                                     => 'Website: ' . $host,
    };
}

/** True when submissions carries the server-written agent_id link (memoised). */
function subtrends_has_agent_col(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try {
        return $c = (bool) db_query(
            "SELECT 1 FROM information_schema.columns WHERE table_name = 'submissions' AND column_name = 'agent_id'"
        )->fetchColumn();
    } catch (Throwable $e) { return $c = false; }
}

/**
 * Compute the trend set. $scopeCond is a boolean SQL fragment over `s`
 * (submissions) — '' for owner/all — and $scopeParams its bound values. $from/$to
 * are Y-m-d bounds on the submission date (inclusive). $type optionally narrows to
 * one submission type.
 *
 * Returns: total, with_dates, avg_party, with_children (enquiries with at least
 * one child), by_property[], by_month[] (requested check-in month), by_party[],
 * by_children[], by_source[], by_lead_time[], by_type[]. Each *_by list is
 * [['label'=>..,'count'=>int], …]. Never throws.
 */
function submission_trends(string $scopeCond, array $scopeParams, string $from, string $to, string $type = ''): array {
    $where  = ['s.created_at::date BETWEEN :from AND :to'];
    $params = [':from' => $from, ':to' => $to] + $scopeParams;
    if ($scopeCond !== '') $where[] = $scopeCond;
    if ($type !== '')      { $where[] = 's.type = :type'; $params[':type'] = $type; }
    $w = implode(' AND ', $where);

    $empty = [
        'total' => 0, 'with_dates' => 0, 'avg_party' => 0.0, 'with_children' => 0,
        'by_property' => [], 'by_month' => [], 'by_party' => [], 'by_children' => [], 'by_source' => [],
        'by_lead_time' => [], 'by_type' => [],
    ];

    try {
        $total = (int) db_query("SELECT COUNT(*) FROM submissions s WHERE $w", $params)->fetchColumn();

        $with_dates = (int) db_query(
            "SELECT COUNT(*) FROM submissions s WHERE $w AND s.check_in IS NOT NULL", $params
        )->fetchColumn();

        $avg = db_query(
            "SELECT AVG(COALESCE(s.guests_adults,0) + COALESCE(s.guests_children,0))
             FROM submissions s
             WHERE $w AND (COALESCE(s.guests_adults,0) + COALESCE(s.guests_children,0)) > 0", $params
        )->fetchColumn();
        $avg_party = $avg !== null ? round((float)$avg, 1) : 0.0;

        // By property (room → venue). Rows with no room show as "General / no property".
        $by_property = db_query(
            "SELECT COALESCE(v.name, 'General / no property') AS label, COUNT(*) AS n
             FROM submissions s
             LEFT JOIN rooms rm ON rm.id = s.room_id
             LEFT JOIN venues v ON v.id = rm.venue_id
             WHERE $w
             GROUP BY COALESCE(v.name, 'General / no property')
             ORDER BY n DESC, label ASC", $params
        )->fetchAll(PDO::FETCH_ASSOC);

        // By requested check-in month (seasonality of the stays people ask for).
        $by_month = db_query(
            "SELECT to_char(s.check_in, 'Mon YYYY') AS label, to_char(s.check_in, 'YYYY-MM') AS ym, COUNT(*) AS n
             FROM submissions s
             WHERE $w AND s.check_in IS NOT NULL
             GROUP BY to_char(s.check_in, 'Mon YYYY'), to_char(s.check_in, 'YYYY-MM')
             ORDER BY ym ASC", $params
        )->fetchAll(PDO::FETCH_ASSOC);

        // By party size bucket.
        $party_rows = db_query(
            "SELECT (COALESCE(s.guests_adults,0) + COALESCE(s.guests_children,0)) AS g, COUNT(*) AS n
             FROM submissions s WHERE $w
             GROUP BY g", $params
        )->fetchAll(PDO::FETCH_ASSOC);
        $party_map = array_fill_keys(subtrends_party_order(), 0);
        foreach ($party_rows as $r) { $party_map[subtrends_party_bucket((int)$r['g'])] += (int)$r['n']; }
        $by_party = [];
        foreach ($party_map as $label => $n) { if ($n > 0) $by_party[] = ['label' => $label, 'count' => $n]; }

        // By children count — the party breakdown above adds adults + children
        // together, so families were invisible. Bucketed like party size.
        $child_rows = db_query(
            "SELECT COALESCE(s.guests_children,0) AS c, COUNT(*) AS n
             FROM submissions s WHERE $w
             GROUP BY c", $params
        )->fetchAll(PDO::FETCH_ASSOC);
        $child_map = array_fill_keys(subtrends_children_order(), 0);
        $with_children = 0;
        foreach ($child_rows as $r) {
            $child_map[subtrends_children_bucket((int)$r['c'])] += (int)$r['n'];
            if ((int)$r['c'] > 0) $with_children += (int)$r['n'];
        }
        $by_children = [];
        foreach ($child_map as $label => $n) { if ($n > 0) $by_children[] = ['label' => $label, 'count' => $n]; }

        // By lead source. Grouped in SQL on the raw tracking columns, then
        // classified in PHP (subtrends_source_label) so the channel rules live in
        // one testable function. agent_id is probed, never assumed.
        $agentExpr = subtrends_has_agent_col() ? '(s.agent_id IS NOT NULL)' : 'FALSE';
        $src_rows = db_query(
            "SELECT COALESCE(s.utm_source,'') AS us, COALESCE(s.utm_medium,'') AS um,
                    COALESCE(s.referrer,'') AS rf, {$agentExpr} AS ag,
                    COALESCE(s.payload_json->>'source','') AS ps, COUNT(*) AS n
             FROM submissions s WHERE $w
             GROUP BY 1,2,3,4,5", $params
        )->fetchAll(PDO::FETCH_ASSOC);
        $own = subtrends_own_hosts();
        $src_map = [];
        foreach ($src_rows as $r) {
            $isAgent = in_array($r['ag'], [true, 't', 1, '1'], true) || $r['ps'] === 'trade-portal';
            $label = match ($r['ps']) {
                'whatsapp'                                 => 'WhatsApp',
                'off-duty-waitlist', 'somewhere-cafe-waitlist' => 'Waitlist sign-up',
                default => subtrends_source_label((string)$r['us'], (string)$r['um'], (string)$r['rf'], $isAgent, $own),
            };
            $src_map[$label] = ($src_map[$label] ?? 0) + (int)$r['n'];
        }
        arsort($src_map);
        $by_source = [];
        foreach ($src_map as $label => $n) $by_source[] = ['label' => (string)$label, 'count' => $n];

        // Lead time = requested check-in minus enquiry date.
        $by_lead_time_raw = db_query(
            "SELECT CASE
                      WHEN (s.check_in - s.created_at::date) < 0  THEN 'Past / same-day'
                      WHEN (s.check_in - s.created_at::date) < 7  THEN 'Within a week'
                      WHEN (s.check_in - s.created_at::date) < 31 THEN '1–4 weeks out'
                      WHEN (s.check_in - s.created_at::date) < 91 THEN '1–3 months out'
                      ELSE '3+ months out'
                    END AS label,
                    COUNT(*) AS n
             FROM submissions s
             WHERE $w AND s.check_in IS NOT NULL
             GROUP BY label", $params
        )->fetchAll(PDO::FETCH_ASSOC);
        // Impose a sensible order.
        $lead_order = ['Past / same-day','Within a week','1–4 weeks out','1–3 months out','3+ months out'];
        $lead_idx = array_column($by_lead_time_raw, 'n', 'label');
        $by_lead_time = [];
        foreach ($lead_order as $lbl) { if (!empty($lead_idx[$lbl])) $by_lead_time[] = ['label' => $lbl, 'count' => (int)$lead_idx[$lbl]]; }

        $by_type = db_query(
            "SELECT s.type AS label, COUNT(*) AS n FROM submissions s WHERE $w GROUP BY s.type ORDER BY n DESC", $params
        )->fetchAll(PDO::FETCH_ASSOC);

        return [
            'total'        => $total,
            'with_dates'   => $with_dates,
            'avg_party'    => $avg_party,
            'with_children'=> $with_children,
            'by_property'  => array_map(fn($r) => ['label' => (string)$r['label'], 'count' => (int)$r['n']], $by_property),
            'by_month'     => array_map(fn($r) => ['label' => (string)$r['label'], 'count' => (int)$r['n']], $by_month),
            'by_party'     => $by_party,
            'by_children'  => $by_children,
            'by_source'    => $by_source,
            'by_lead_time' => $by_lead_time,
            'by_type'      => array_map(fn($r) => ['label' => (string)$r['label'], 'count' => (int)$r['n']], $by_type),
        ];
    } catch (Throwable $e) {
        error_log('[submission-trends] ' . $e->getMessage());
        return $empty;
    }
}
