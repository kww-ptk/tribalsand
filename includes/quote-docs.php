<?php
declare(strict_types=1);
/**
 * Quote documents (Quote builder, Part C) — quotes saved on an enquiry, the
 * printable A4 quotation, and the owner-editable quote terms.
 *
 * - qb_quote_save() stores a quote option on an enquiry (submission_quotes,
 *   migration add_submission_quotes.sql). It RE-PRICES the selection with
 *   qb_price_selection() — client figures are never stored — and snapshots the
 *   priced quote, the selection, the guest's request block, the FX rate and the
 *   terms in force, so a saved quote always reopens at the prices it was quoted at.
 * - qb_quote_document() is PURE: it turns a priced quote + meta into the view
 *   model admin/quote-print.php renders. Amounts are formatted here; escaping is
 *   the template's job (everything goes through e()).
 * - Every DB read is pre-migration-safe (qb_quotes_supported(), a catalog lookup).
 *
 * Depends on includes/db.php; requires quote-builder.php, booking.php
 * (make_submission_ref) and submission-notes.php.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/quote-builder.php';
require_once __DIR__ . '/booking.php';
require_once __DIR__ . '/submission-notes.php';

const QB_QUOTE_TERMS_DEFAULT = 'This quote is not a reservation. Prices are valid on the date issued and subject to availability until booked.';
const QB_QUOTE_CONTACT = ['email' => 'reservations@tribalsand.com', 'phone' => '+254 115 115 247', 'web' => 'tribalsand.com'];

/** A refusal the caller shows to staff (not a crash): out of scope, nothing priced, unknown enquiry, no table. */
class QbQuoteRefusal extends RuntimeException {}

/**
 * True once add_submission_quotes.sql has run. Catalog lookup (to_regclass never
 * fails), so it is safe inside a transaction. Memoised; $refresh re-reads.
 */
function qb_quotes_supported(bool $refresh = false): bool {
    static $ok = null;
    if ($ok !== null && !$refresh) return $ok;
    try {
        return $ok = (bool) db_query("SELECT to_regclass('submission_quotes') IS NOT NULL")->fetchColumn();
    } catch (Throwable $e) { return $ok = false; }
}

/** The owner's quote terms (Admin → Settings), or the default when unset/blank or unreadable. */
function qb_quote_terms(): string {
    try { $t = trim(setting('quote_terms', '')); } catch (Throwable $e) { $t = ''; }
    return $t !== '' ? $t : QB_QUOTE_TERMS_DEFAULT;
}

/**
 * Quote number. On an enquiry: "TSR-1234-a1b2c3 · Option 2" (the enquiry ref,
 * make_submission_ref()); an enquiry without an option: its ref alone; outside
 * an enquiry: "Q-YYYYMMDD-HHMM" from $now (Nairobi-local, like the whole app).
 */
function qb_quote_ref(?int $submissionId, ?int $optionNo, string $now): string {
    if ($submissionId !== null && $submissionId > 0) {
        $ref = make_submission_ref($submissionId);
        return ($optionNo !== null && $optionNo > 0) ? $ref . ' · Option ' . $optionNo : $ref;
    }
    $ts = strtotime($now);
    return 'Q-' . date('Ymd-Hi', $ts === false ? time() : $ts);
}

/** A saved quote's copy/insert text: the ref first, then the quote. */
function qb_quote_text_with_ref(string $text, string $ref): string {
    return $ref . "\n" . $text;
}

/** "2 adults, 1 child" ('' when nobody is known). */
function qb_party_text(int $adults, int $children): string {
    $out = [];
    if ($adults > 0)   $out[] = $adults . ' adult' . ($adults === 1 ? '' : 's');
    if ($children > 0) $out[] = $children . ' child' . ($children === 1 ? '' : 'ren');
    return implode(', ', $out);
}

/**
 * What the guest asked for on the enquiry, as [label, value] rows: dates, party,
 * the room (with its property) or the property alone, the activity. PURE.
 * Never the free-text message — it can hold anything, and this goes on a document.
 * Keys read: check_in, check_out, guests_adults, guests_children, room_name,
 * venue_name, tour_name.
 */
function qb_request_block(array $sub): array {
    $rows = [];
    $ci = rates_window_ymd((string)($sub['check_in'] ?? ''));
    $co = rates_window_ymd((string)($sub['check_out'] ?? ''));
    if ($ci !== null && $co !== null && $ci < $co) {
        $n = max(1, (int)round((strtotime($co) - strtotime($ci)) / 86400));
        $rows[] = ['label' => 'Dates', 'value' => date('j M Y', strtotime($ci)) . ' → ' . date('j M Y', strtotime($co))
                                                . ' · ' . $n . ' night' . ($n === 1 ? '' : 's')];
    } elseif ($ci !== null) {
        $rows[] = ['label' => 'Dates', 'value' => 'from ' . date('j M Y', strtotime($ci))];
    }
    $party = qb_party_text(max(0, (int)($sub['guests_adults'] ?? 0)), max(0, (int)($sub['guests_children'] ?? 0)));
    if ($party !== '') $rows[] = ['label' => 'Guests', 'value' => $party];
    $room  = trim((string)($sub['room_name'] ?? ''));
    $venue = trim((string)($sub['venue_name'] ?? ''));
    if ($room !== '')      $rows[] = ['label' => 'Room', 'value' => qb_room_display_name($venue, $room)];
    elseif ($venue !== '') $rows[] = ['label' => 'Property', 'value' => $venue];
    $tour = trim((string)($sub['tour_name'] ?? ''));
    if ($tour !== '') $rows[] = ['label' => 'Activity', 'value' => $tour];
    return $rows;
}

/**
 * The printable quotation's view model. PURE. $priced = qb_price_selection()
 * output (or a saved snapshot of it); $meta: ref, issued (Y-m-d), request rows;
 * $terms: raw text (the template escapes it and keeps line breaks).
 */
function qb_quote_document(array $priced, array $meta, string $terms): array {
    $cur  = (string)($priced['currency'] ?? 'KES');
    $fmt  = fn($a) => qb_fmt((float)$a, $cur);
    $sum  = (array)($priced['summary'] ?? []);
    $issuedYmd = (string)($meta['issued'] ?? ($priced['issued'] ?? date('Y-m-d')));
    $nights = (int)($priced['nights'] ?? 0);
    $ci = (string)($priced['check_in'] ?? ''); $co = (string)($priced['check_out'] ?? '');

    $rooms = [];
    foreach ((array)($priced['quote_rooms'] ?? []) as $r) {
        $rooms[] = ['property' => (string)$r['venue'], 'room' => (string)$r['room'], 'qty' => (int)$r['qty'],
                    'mix' => qb_guest_mix((string)$r['mix']), 'amount' => $fmt($r['amt'])];
    }
    $extras = [];
    foreach ((array)($priced['quote_extras'] ?? []) as $x) $extras[] = ['label' => (string)$x['label'], 'amount' => $fmt($x['amt'])];

    $discount = null;
    if ((float)($sum['discount'] ?? 0) > 0) {
        $label = 'Discount';
        foreach ((array)($priced['lines'] ?? []) as $l) if (($l['kind'] ?? '') === 'discount') { $label = (string)$l['label']; break; }
        $discount = ['label' => $label, 'amount' => '−' . $fmt($sum['discount'])];
    }

    return [
        'title'  => 'Quotation',
        'ref'    => (string)($meta['ref'] ?? ''),
        'issued' => date('j M Y', strtotime($issuedYmd) ?: time()),
        'prepared_for' => [
            'name'   => trim((string)($priced['name'] ?? ($meta['name'] ?? ''))),
            'stay'   => ($nights > 0 && $ci !== '' && $co !== '') ? date('j M Y', strtotime($ci)) . ' → ' . date('j M Y', strtotime($co)) : '',
            'nights' => $nights > 0 ? $nights . ' night' . ($nights === 1 ? '' : 's') : '',
            'party'  => qb_party_text((int)($priced['adults'] ?? 0), (int)($priced['children'] ?? 0)),
        ],
        'request'       => array_values((array)($meta['request'] ?? [])),
        'rooms'         => $rooms,
        'accommodation' => $rooms ? $fmt($sum['accommodation'] ?? 0) : null,
        'discount'      => $discount,
        'extras'        => $extras,
        'total'         => $fmt($sum['total'] ?? 0),
        'currency'      => $cur,
        'fx_note'       => !empty($priced['fx_note']) ? (string)$priced['fx_note'] : null,
        // Maya Ilai: "* A Resort Fee of $20 per person is applied, to be paid on-site."
        'fee_note'      => !empty($priced['fee_note']) ? (string)$priced['fee_note'] : null,
        'terms'         => $terms,
        'contact'       => QB_QUOTE_CONTACT,
    ];
}

/** Does a priced quote have at least one line besides the total? (Total 0 is fine — e.g. 100% off.) */
function qb_quote_has_priced_lines(array $priced): bool {
    foreach ((array)($priced['lines'] ?? []) as $l) if (($l['kind'] ?? '') !== 'total') return true;
    return false;
}

/**
 * The priced quote as stored in a snapshot: what the printed page and a reprint
 * need (lines, summary, document rows, fx, party/dates, copy text) — NOT the
 * catalogue rows (`rooms`: every room with free counts), extras echo or notices.
 */
function qb_quote_snapshot_quote(array $priced): array {
    $keep = ['currency', 'check_in', 'check_out', 'nights', 'name', 'adults', 'children', 'issued',
             'summary', 'lines', 'fx_note', 'fee_note', 'text', 'quote_rooms', 'quote_extras'];
    return array_intersect_key($priced, array_flip($keep));
}

/** The selection as stored in a snapshot: only the fields the builder reads, bounded. */
function qb_selection_snapshot(array $sel): array {
    $rooms = [];
    foreach (array_slice((array)($sel['rooms'] ?? []), 0, 200) as $r) {
        $id = (int)($r['id'] ?? 0); $qty = max(0, (int)($r['qty'] ?? 0));
        if ($id > 0 && $qty > 0) $rooms[] = ['id' => $id, 'qty' => $qty, 'guests' => max(0, (int)($r['guests'] ?? 0))];
    }
    $extras = [];
    foreach (array_slice((array)($sel['extras'] ?? []), 0, 50) as $x) {
        $extras[] = [
            'key' => substr(preg_replace('/[^a-z0-9_-]/i', '', (string)($x['key'] ?? '')), 0, 20),
            'kind' => substr((string)($x['kind'] ?? ''), 0, 10), 'id' => (int)($x['id'] ?? 0),
            'label' => mb_substr((string)($x['label'] ?? ''), 0, 120), 'qty' => max(0, min(1000, (int)($x['qty'] ?? 0))),
            'price' => isset($x['price']) && is_numeric($x['price']) ? (float)$x['price'] : null,
            'edited' => !empty($x['edited']),
            'price_cur' => substr((string)($x['price_cur'] ?? ''), 0, 3), 'basis' => substr((string)($x['basis'] ?? ''), 0, 10),
        ];
    }
    return [
        'name' => mb_substr((string)($sel['name'] ?? ''), 0, 120),
        'check_in' => substr((string)($sel['check_in'] ?? ''), 0, 10), 'check_out' => substr((string)($sel['check_out'] ?? ''), 0, 10),
        'adults' => max(0, (int)($sel['adults'] ?? 0)), 'children' => max(0, (int)($sel['children'] ?? 0)),
        'discount_pct' => (float)($sel['discount_pct'] ?? 0), 'discount_note' => mb_substr((string)($sel['discount_note'] ?? ''), 0, 80),
        'cur' => substr((string)($sel['cur'] ?? ''), 0, 3), 'rooms' => $rooms, 'extras' => $extras,
    ];
}

/** The enquiry row a quote is saved on (with the names the request block shows), or null. */
function qb_quote_submission(int $submissionId): ?array {
    $row = db_query(
        "SELECT s.id, s.room_id, s.check_in, s.check_out, s.guests_adults, s.guests_children,
                r.name AS room_name, r.venue_id, v.name AS venue_name, t.name AS tour_name
           FROM submissions s
           LEFT JOIN rooms r  ON r.id = s.room_id
           LEFT JOIN venues v ON v.id = r.venue_id
           LEFT JOIN tours t  ON t.id = s.tour_id
          WHERE s.id = :id",
        [':id' => $submissionId]
    )->fetch();
    return $row ?: null;
}

/**
 * Is this enquiry in $scope (admin_venue_ids(): null = all)? Same rule as
 * submission_in_scope(), parameterised: no property attached = in scope.
 */
function qb_submission_in_scope(array $sub, ?array $scope): bool {
    if ($scope === null) return true;
    if ($sub['room_id'] === null) return true;
    if (!$scope || $sub['venue_id'] === null) return false;
    return in_array((int)$sub['venue_id'], array_map('intval', $scope), true);
}

/**
 * Save a quote option on an enquiry. Re-prices $sel (untrusted) with
 * qb_price_selection($sel, $scope); refuses (QbQuoteRefusal) when the table is
 * missing, the enquiry is unknown or out of scope, or nothing is priced. The
 * option number is the next per enquiry, allocated under a row lock on the
 * enquiry (concurrent saves queue; UNIQUE(submission_id, option_no) backstops).
 * Opens a transaction only when the caller has not. Adds an internal thread note
 * when the thread exists (in a SAVEPOINT, so a failed note never loses the quote).
 * @return array{id:int, option_no:int, ref:string, total:float, currency:string, text:string, created:string}
 */
function qb_quote_save(int $submissionId, ?int $adminId, array $sel, ?array $scope): array {
    if (!qb_quotes_supported()) throw new QbQuoteRefusal('Saving quotes needs the add_submission_quotes migration.');
    $sub = $submissionId > 0 ? qb_quote_submission($submissionId) : null;
    if (!$sub) throw new QbQuoteRefusal('Enquiry not found.');
    if (!qb_submission_in_scope($sub, $scope)) throw new QbQuoteRefusal('Enquiry not found.');

    $priced = qb_price_selection($sel, $scope);
    $total  = (float)$priced['summary']['total'];
    // Refuse only when nothing is priced. A priced quote can total 0 (a 100% discount) and is still a quote.
    if (!qb_quote_has_priced_lines($priced)) throw new QbQuoteRefusal('Nothing is priced yet.');

    $cur = (string)$priced['currency'];
    $pdo = db();
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();
    try {
        db_query('SELECT id FROM submissions WHERE id = :id FOR UPDATE', [':id' => $submissionId]);
        $n = (int)db_query('SELECT COALESCE(MAX(option_no), 0) + 1 FROM submission_quotes WHERE submission_id = :s',
                           [':s' => $submissionId])->fetchColumn();
        $now = date('Y-m-d H:i:s');
        $ref = qb_quote_ref($submissionId, $n, $now);
        $rates = fx_rates()['rates'];
        $snapshot = [
            'v' => 1,
            'quote' => qb_quote_snapshot_quote($priced),
            'sel'   => qb_selection_snapshot($sel),
            'meta'  => [
                'ref' => $ref, 'issued' => date('Y-m-d', strtotime($now)), 'currency' => $cur,
                'name' => (string)$priced['name'], 'adults' => (int)$priced['adults'], 'children' => (int)$priced['children'],
                'request' => qb_request_block($sub),
                'fx_rate' => (float)($rates['KES'] ?? 0), 'fx_converted' => !empty($priced['fx_note']),
                'terms' => qb_quote_terms(),
            ],
        ];
        $id = (int)db_query(
            'INSERT INTO submission_quotes (submission_id, option_no, admin_id, currency, total, snapshot_json)
             VALUES (:s, :n, :a, :c, :t, CAST(:j AS JSONB)) RETURNING id',
            [':s' => $submissionId, ':n' => $n, ':a' => $adminId ?: null, ':c' => $cur, ':t' => $total,
             ':j' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]
        )->fetchColumn();

        if (submission_notes_supported()) {
            $author = '';
            if ($adminId) {
                $a = db_query('SELECT name, email FROM admin_users WHERE id = :id', [':id' => $adminId])->fetch();
                if ($a) $author = trim((string)($a['name'] ?? '')) ?: (string)($a['email'] ?? '');
            }
            // add_submission_note() swallows its own errors, but in Postgres a failed
            // statement aborts the transaction — the savepoint keeps the quote.
            $pdo->exec('SAVEPOINT qb_quote_note');
            $ok = add_submission_note($submissionId, $adminId, 'Quote Option ' . $n . ' saved — ' . qb_fmt($total, $cur), 'note', $author);
            $pdo->exec($ok ? 'RELEASE SAVEPOINT qb_quote_note' : 'ROLLBACK TO SAVEPOINT qb_quote_note');
        }
        if ($ownTx) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return [
        'id' => $id, 'option_no' => $n, 'ref' => $ref, 'total' => $total, 'currency' => $cur,
        'text' => qb_quote_text_with_ref((string)$priced['text'], $ref), 'created' => $now,
    ];
}

/** One saved quote with its snapshot decoded (null when missing or pre-migration). */
function qb_quote_fetch(int $id): ?array {
    if ($id <= 0 || !qb_quotes_supported()) return null;
    $row = db_query('SELECT * FROM submission_quotes WHERE id = :id', [':id' => $id])->fetch();
    if (!$row) return null;
    $row['snapshot'] = json_decode((string)$row['snapshot_json'], true) ?: [];
    return $row;
}

/** The quotes saved on an enquiry, oldest option first, with who saved them. */
function qb_quotes_for_submission(int $submissionId): array {
    if ($submissionId <= 0 || !qb_quotes_supported()) return [];
    $rows = db_query(
        "SELECT q.id, q.option_no, q.currency, q.total, q.created_at, q.admin_id,
                COALESCE(NULLIF(TRIM(a.name), ''), a.email) AS admin_name
           FROM submission_quotes q
           LEFT JOIN admin_users a ON a.id = q.admin_id
          WHERE q.submission_id = :s
          ORDER BY q.option_no ASC",
        [':s' => $submissionId]
    )->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['option_no'] = (int)$r['option_no']; $r['total'] = (float)$r['total']; }
    unset($r);
    return $rows;
}
