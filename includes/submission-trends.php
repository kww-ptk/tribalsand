<?php
declare(strict_types=1);
/**
 * Submission "smart trends" — read-only aggregation over the submissions inbox
 * so the team can see, at a glance, what kind of enquiries come in most: which
 * property, which requested dates, and what party sizes.
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

/**
 * Compute the trend set. $scopeCond is a boolean SQL fragment over `s`
 * (submissions) — '' for owner/all — and $scopeParams its bound values. $from/$to
 * are Y-m-d bounds on the submission date (inclusive). $type optionally narrows to
 * one submission type.
 *
 * Returns: total, with_dates, avg_party, by_property[], by_month[] (requested
 * check-in month), by_party[], by_lead_time[], by_type[]. Each *_by list is
 * [['label'=>..,'count'=>int], …]. Never throws.
 */
function submission_trends(string $scopeCond, array $scopeParams, string $from, string $to, string $type = ''): array {
    $where  = ['s.created_at::date BETWEEN :from AND :to'];
    $params = [':from' => $from, ':to' => $to] + $scopeParams;
    if ($scopeCond !== '') $where[] = $scopeCond;
    if ($type !== '')      { $where[] = 's.type = :type'; $params[':type'] = $type; }
    $w = implode(' AND ', $where);

    $empty = [
        'total' => 0, 'with_dates' => 0, 'avg_party' => 0.0,
        'by_property' => [], 'by_month' => [], 'by_party' => [], 'by_lead_time' => [], 'by_type' => [],
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
            'by_property'  => array_map(fn($r) => ['label' => (string)$r['label'], 'count' => (int)$r['n']], $by_property),
            'by_month'     => array_map(fn($r) => ['label' => (string)$r['label'], 'count' => (int)$r['n']], $by_month),
            'by_party'     => $by_party,
            'by_lead_time' => $by_lead_time,
            'by_type'      => array_map(fn($r) => ['label' => (string)$r['label'], 'count' => (int)$r['n']], $by_type),
        ];
    } catch (Throwable $e) {
        error_log('[submission-trends] ' . $e->getMessage());
        return $empty;
    }
}
