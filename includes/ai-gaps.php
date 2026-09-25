<?php
declare(strict_types=1);
/**
 * AI gaps — where the guest concierge fails, read from concierge_log.
 *
 * The concierge already logs every answered turn (question, answer, tools used,
 * ok). This file turns that log into a to-do list for the owner: which guest
 * questions it could not answer well, grouped by topic, so the missing facts can
 * be added (Admin → AI settings, property copy, or a new lookup tool).
 *
 * READ-ONLY. The classifier (ai_gap_reason / ai_gap_topic / ai_gap_classify)
 * is pure and unit-tested (tests/ai_gaps_logic.php); ai_gaps_fetch() is the only
 * DB read, and it is pre-migration-safe via concierge_log_supported().
 *
 * Classification is in PHP, not SQL, because "the answer sounds unsure" and
 * "the guest asked the same thing again" are text rules. The window is capped
 * (AI_GAPS_MAX_ROWS) so a busy month can never make the page slow.
 */
require_once __DIR__ . '/concierge.php';   // concierge_log_supported()

const AI_GAPS_MAX_ROWS   = 5000;   // newest turns classified per page load
const AI_GAPS_REPEAT_PCT = 80.0;   // similar_text() % that counts as "asked again"

/** Reasons a turn is flagged, most serious first. Keys are stable (URL filter values). */
function ai_gap_reasons(): array {
    return [
        'failed'    => 'Failed to answer',
        'unsure'    => 'Said it didn\'t know',
        'no_lookup' => 'Gave a price without checking',
        'repeat'    => 'Guest asked again',
    ];
}

/** Topic buckets, checked in order — the first match wins, so specific topics sit above generic ones. */
function ai_gap_topics(): array {
    return [
        'transfer'     => ['label' => 'Transfers & travel', 'words' => ['airport', 'transfer*', 'taxi', 'pick up', 'pickup', 'pick-up', 'shuttle', 'flight', 'drive from', 'get there', 'directions', 'uber']],
        'pets'         => ['label' => 'Pets',               'words' => ['pet', 'dog', 'cat', 'kitten', 'puppy', 'animal']],
        'checkin'      => ['label' => 'Check-in & out',     'words' => ['check-in', 'check in', 'checkin', 'check-out', 'check out', 'checkout', 'early arrival', 'late arrival', 'arrive late', 'luggage']],
        'food'         => ['label' => 'Food & dining',      'words' => ['breakfast', 'meal*', 'food', 'restaurant', 'dinner', 'lunch', 'menu', 'vegan', 'vegetarian', 'halal', 'chef', 'drink', 'bar']],
        'activities'   => ['label' => 'Activities & tours', 'words' => ['tour*', 'activit*', 'snorkel*', 'dive', 'diving', 'kite*', 'safari', 'excursion*', 'boat', 'fish*', 'yoga', 'massage', 'spa']],
        'price'        => ['label' => 'Prices & payment',   'words' => ['price', 'cost', 'rate', 'how much', '$', 'kes', 'usd', 'ksh', 'discount*', 'deposit', 'pay*', 'refund*', 'cancel*']],
        'availability' => ['label' => 'Availability & dates', 'words' => ['availab*', 'free on', 'dates', 'book*', 'reserv*', 'nights', 'weekend']],
        'rooms'        => ['label' => 'Rooms & facilities', 'words' => ['room', 'bed', 'villa', 'view', 'pool', 'wifi', 'wi-fi', 'internet', 'aircon', 'air con', 'air-con', 'kitchen', 'parking', 'beach']],
        'other'        => ['label' => 'Other',              'words' => []],
    ];
}

/** Lower-cased, whitespace-collapsed copy of a string (for matching). */
function ai_gap_norm(string $s): string {
    return trim((string)preg_replace('/\s+/u', ' ', mb_strtolower($s)));
}

/**
 * Does $text contain $word as a whole word (plural -s/-es allowed)? A word
 * ending in '*' is a prefix ("activit*" → activity/activities). Whole-word by
 * default because short keys misfire as prefixes: "cat" would match "cater",
 * "pet" "carpet", "rate" "accurate". Keys ending in a symbol ('$') only need
 * the leading boundary, so "$300" still matches.
 */
function ai_gap_has_word(string $text, string $word): bool {
    $w = trim($word);
    if ($w === '') return false;
    $prefix = str_ends_with($w, '*');
    if ($prefix) $w = substr($w, 0, -1);
    $re = '/(?<![\p{L}\p{N}])' . preg_quote($w, '/');
    if (!$prefix && preg_match('/[\p{L}\p{N}]$/u', $w)) $re .= '(?:s|es)?(?![\p{L}\p{N}])';
    return (bool)preg_match($re . '/u', $text);
}

/** Which topic a guest question belongs to (a key of ai_gap_topics()). */
function ai_gap_topic(string $question): string {
    $q = ai_gap_norm($question);
    foreach (ai_gap_topics() as $key => $t) {
        foreach ($t['words'] as $w) {
            if (ai_gap_has_word($q, $w)) return $key;
        }
    }
    return 'other';
}

/** Does the AI's answer admit it didn't know / hand the guest off? */
function ai_gap_answer_unsure(string $answer): bool {
    $a = ai_gap_norm(str_replace(['’', '‘'], "'", $answer));
    if ($a === '') return true;
    foreach ([
        "i don't have", 'i do not have', "i'm not sure", 'i am not sure', "don't know", 'do not know',
        "i'm not able", 'i am not able', 'not able to help', 'unable to', "i can't", 'i cannot',
        "couldn't find", 'could not find', 'no information', "don't have information", 'not have details',
        'please contact', 'contact our', 'contact the', 'reach out to', 'get in touch',
    ] as $p) {
        if (str_contains($a, $p)) return true;
    }
    return false;
}

/** Is this a factual question the AI should have looked up (price, dates, availability)? */
function ai_gap_is_factual(string $question): bool {
    $q = ai_gap_norm($question);
    if (preg_match('/\d/', $q)) return true;   // dates, guest counts, amounts
    foreach (['price', 'cost', 'how much', 'rate', 'available', 'availability', 'free on', 'book', 'nights', 'per night', '$', 'kes', 'usd'] as $w) {
        if (ai_gap_has_word($q, $w)) return true;
    }
    return false;
}

/**
 * Does the answer state a price? A currency marker next to a number ("$300",
 * "KES 81,510", "1,200 USD", "61.5k"). Used so "answered without checking"
 * flags a figure given with no lookup — NOT a clarifying question, which is the
 * right reply to a vague "price for 3 nights in March?".
 */
function ai_gap_answer_has_price(string $answer): bool {
    $a = ai_gap_norm($answer);
    $cur = '(?:\$|usd|kes|ksh|kshs|€|£|eur|gbp)';
    $num = '\d[\d,]*(?:\.\d+)?\s*k?';
    return (bool)preg_match('/' . $cur . '\s*' . $num . '|' . $num . '\s*' . $cur . '(?![\p{L}])/u', $a);
}

/**
 * Why a single logged turn is a gap, or null when it looks fine.
 * $row: ['question','answer','tool_count','ok']. Repeats need the rest of the
 * session, so ai_gap_classify() marks those.
 * `no_lookup` = a price/date question answered WITH a price but with zero tool
 * calls, i.e. the figure can't have come from our rates. A clarifying question
 * back ("which dates?") with no lookup is correct behaviour and is not flagged.
 */
function ai_gap_reason(array $row): ?string {
    $ok = $row['ok'] ?? true;
    if ($ok === false || $ok === 'f' || $ok === 0 || $ok === '0') return 'failed';
    if (ai_gap_answer_unsure((string)($row['answer'] ?? ''))) return 'unsure';
    if ((int)($row['tool_count'] ?? 0) === 0
        && ai_gap_is_factual((string)($row['question'] ?? ''))
        && ai_gap_answer_has_price((string)($row['answer'] ?? ''))) return 'no_lookup';
    return null;
}

/** Near-identical questions? (the guest re-asked because the first answer didn't help) */
function ai_gap_same_question(string $a, string $b): bool {
    $a = ai_gap_norm($a); $b = ai_gap_norm($b);
    if ($a === '' || $b === '') return false;
    if ($a === $b) return true;
    similar_text($a, $b, $pct);
    return $pct >= AI_GAPS_REPEAT_PCT;
}

/**
 * Classify a list of turns (any order). Returns the flagged ones, newest first,
 * each with 'reason' and 'topic' added. A turn whose own answer looks fine is
 * still flagged 'repeat' when the SAME session asks it again later — the earlier
 * answer is the one that didn't land, so that is the row we flag.
 */
function ai_gap_classify(array $rows): array {
    usort($rows, fn($x, $y) => strcmp((string)$x['created_at'], (string)$y['created_at']) ?: ((int)$x['id'] <=> (int)$y['id']));

    $bySession = [];
    foreach ($rows as $i => $r) {
        $sid = (string)($r['session_id'] ?? '');
        if ($sid !== '') $bySession[$sid][] = $i;
    }
    $repeated = [];
    foreach ($bySession as $idx) {
        $n = count($idx);
        for ($a = 0; $a < $n; $a++) {
            for ($b = $a + 1; $b < $n; $b++) {
                if (ai_gap_same_question((string)$rows[$idx[$a]]['question'], (string)$rows[$idx[$b]]['question'])) {
                    $repeated[$idx[$a]] = true;
                    break;
                }
            }
        }
    }

    $out = [];
    foreach ($rows as $i => $r) {
        $reason = ai_gap_reason($r) ?? (isset($repeated[$i]) ? 'repeat' : null);
        if ($reason === null) continue;
        $r['reason'] = $reason;
        $r['topic']  = ai_gap_topic((string)($r['question'] ?? ''));
        $out[] = $r;
    }
    return array_reverse($out);
}

/** Counts per topic (for the summary chips), largest first, zeros dropped. */
function ai_gap_topic_counts(array $gaps): array {
    $c = [];
    foreach ($gaps as $g) $c[$g['topic']] = ($c[$g['topic']] ?? 0) + 1;
    arsort($c);
    return $c;
}

/**
 * The newest turns in the last $days days, Nairobi time (the DB session runs in
 * Africa/Nairobi). Returns ['rows' => [...], 'total' => int, 'capped' => bool].
 * Never selects client_ip — the page has no reason to show who asked.
 */
function ai_gaps_fetch(int $days): array {
    if (!concierge_log_supported()) return ['rows' => [], 'total' => 0, 'capped' => false];
    $days = max(1, min(365, $days));
    $rows = db_query(
        "SELECT id, session_id, question, answer, tools_used, tool_count, ok, created_at
           FROM concierge_log
          WHERE created_at > now() - (:d || ' days')::interval
          ORDER BY created_at DESC, id DESC
          LIMIT " . (AI_GAPS_MAX_ROWS + 1),
        [':d' => (string)$days]
    )->fetchAll();
    $capped = count($rows) > AI_GAPS_MAX_ROWS;
    if ($capped) array_pop($rows);
    foreach ($rows as &$r) $r['ok'] = in_array($r['ok'], [true, 't', 1, '1', 'true'], true);
    unset($r);
    return ['rows' => $rows, 'total' => count($rows), 'capped' => $capped];
}
