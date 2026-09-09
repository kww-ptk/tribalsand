<?php
declare(strict_types=1);
/**
 * Assistant tool-layer tests — the READ-ONLY wrappers the AI calls.
 * Run: php tests/assistant_tools.php
 *
 * The AI/model call is NOT exercised here (that costs money and is non-
 * deterministic). We assert the tool wrappers directly against live DB rows —
 * they are the "truth" side of the assistant, so they are what must be correct.
 * Everything here is read-only, so there is nothing to roll back.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/assistant-tools.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Pure logic (no DB) ───────────────────────────────────────────────────────
$defs = assistant_tool_definitions();
$names = array_map(fn($t) => $t['name'], $defs);
check('three tools defined',                 count($defs) === 3);
check('has check_availability',              in_array('check_availability', $names, true));
check('has quote_stay',                      in_array('quote_stay', $names, true));
check('has list_properties',                 in_array('list_properties', $names, true));
foreach ($defs as $t) {
    check("tool {$t['name']} has description", trim((string)($t['description'] ?? '')) !== '');
    check("tool {$t['name']} has object schema", (($t['input_schema']['type'] ?? '') === 'object'));
}
check('check_availability requires dates',   ($defs[1]['input_schema']['required'] ?? []) === ['check_in', 'check_out']);
check('quote_stay requires room+dates',      ($defs[2]['input_schema']['required'] ?? []) === ['room', 'check_in', 'check_out']);

check('scope: null (owner) → in scope',      assistant_in_scope(null, 7) === true);
check('scope: id in set → in scope',         assistant_in_scope([5, 7], 7) === true);
check('scope: id not in set → out',          assistant_in_scope([5, 6], 7) === false);
check('scope: empty set → out',              assistant_in_scope([], 7) === false);

check('today is YYYY-MM-DD',                 (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', assistant_today_ymd()));

$w = assistant_validate_window(['check_in' => '', 'check_out' => '2099-01-02']);
check('window: missing → error+need dates',  ($w['error'] ?? '') !== '' && ($w['need'] ?? '') === 'dates');
$w = assistant_validate_window(['check_in' => 'not-a-date', 'check_out' => '2099-01-02']);
check('window: garbage → error',             isset($w['error']));
$w = assistant_validate_window(['check_in' => '2099-01-05', 'check_out' => '2099-01-05']);
check('window: co<=ci → error',              isset($w['error']));
$w = assistant_validate_window(['check_in' => '2099-1-5', 'check_out' => '2099-01-08']);
check('window: normalises then passes',      $w === ['2099-01-05', '2099-01-08']);

$sys = assistant_system_prompt(null);
check('prompt names today',                  str_contains($sys, assistant_today_ymd()));
check('prompt: owner sees every property',   str_contains($sys, 'every property'));
check('prompt forbids inventing',            stripos($sys, 'never invent') !== false || stripos($sys, 'Never invent') !== false);
$sysScoped = assistant_system_prompt([5]);
check('prompt: scoped wording differs',      $sysScoped !== $sys);

// ── Phase 4: enquiry-reply draft brief (pure) ────────────────────────────────
$brief = assistant_build_draft_brief([
    'guest_name' => 'Jane Doe', 'venue_name' => 'Zuri', 'room_name' => 'Jua',
    'check_in' => '2099-03-10', 'check_out' => '2099-03-13',
    'guests_adults' => 5, 'guests_children' => 2, 'message' => 'Anniversary trip!',
]);
check('draft brief: names the guest',        str_contains($brief, 'Jane Doe'));
check('draft brief: room + property',        str_contains($brief, 'Jua at Zuri'));
check('draft brief: carries both dates',     str_contains($brief, '2099-03-10') && str_contains($brief, '2099-03-13'));
check('draft brief: carries party size',     str_contains($brief, '5 adults') && str_contains($brief, '2 children'));
check('draft brief: carries their message',  str_contains($brief, 'Anniversary trip!'));
check('draft brief: forbids inventing',      stripos($brief, 'never invent') !== false);
$briefEmpty = assistant_build_draft_brief([]);
check('draft brief: tolerates empty row',    str_contains($briefEmpty, 'the guest') && str_contains($briefEmpty, 'not specified'));

// ── Provider adapter surface (no network) ────────────────────────────────────
check('provider defaults non-empty',         ai_provider() !== '');
check('model resolves non-empty',            ai_model() !== '');
check('supported() returns bool',            is_bool(ai_assistant_supported()));
// chat_with_tools must fail-soft (never throw) when unconfigured OR for an
// unknown provider — assert the shape without hitting the network.
if (!ai_assistant_supported()) {
    $r = chat_with_tools('sys', [['role' => 'user', 'text' => 'hi']], $defs, fn($n, $a) => []);
    check('unconfigured → {ok:false,error}',  ($r['ok'] ?? true) === false && isset($r['error']));
} else {
    echo "SKIP  unconfigured-path (a key IS set in this env)\n";
}

// Dispatcher: unknown tool never throws.
$r = assistant_run_tool('does_not_exist', [], null);
check('unknown tool → error, no throw',       isset($r['error']));

// ── DB-backed wrappers (read-only) ───────────────────────────────────────────
try {
    $room = db_query(
        "SELECT r.id, r.slug, r.venue_id FROM rooms r
           JOIN venues v ON v.id = r.venue_id
          WHERE r.is_published = TRUE AND v.is_published = TRUE
          ORDER BY r.id LIMIT 1"
    )->fetch();
} catch (\Throwable $e) {
    echo "\nSKIP  DB wrappers (database unavailable: " . $e->getMessage() . ")\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
    exit($failures ? 1 : 0);
}

if (!$room) {
    echo "\nSKIP  DB wrappers (no published room seeded)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
    exit($failures ? 1 : 0);
}
$slug    = (string)$room['slug'];
$venueId = (int)$room['venue_id'];
$ci = '2099-06-15';
$co = '2099-06-18';   // far-future window → certainly free

// list_properties
$lp = assistant_tool_list_properties(null);
check('list_properties: returns some',       !empty($lp['properties']));
$slugs = array_map(fn($p) => $p['slug'], $lp['properties'] ?? []);
check('list_properties: includes our venue', in_array((string)db_query('SELECT slug FROM venues WHERE id=:id', [':id' => $venueId])->fetchColumn(), $slugs, true));
$lpScoped = assistant_tool_list_properties([$venueId]);
check('list_properties: scoped to one',      count($lpScoped['properties'] ?? []) === 1);
$lpEmpty = assistant_tool_list_properties([]);
check('list_properties: empty scope → none', ($lpEmpty['properties'] ?? null) === [] && isset($lpEmpty['note']));

// quote_stay
$q = assistant_tool_quote_stay(['room' => $slug, 'check_in' => $ci, 'check_out' => $co], null);
check('quote_stay: prices a real room',      !isset($q['error']) && ($q['nights'] ?? 0) === 3);
check('quote_stay: total > 0',               ($q['total'] ?? 0) > 0);
check('quote_stay: nightly = total/nights',  isset($q['price_per_night']) && abs($q['price_per_night'] - round($q['total'] / $q['nights'], 2)) < 0.01);
check('quote_stay: has currency',            trim((string)($q['currency'] ?? '')) !== '');
check('quote_stay: available is bool',        is_bool($q['available'] ?? null));

// quote_stay matches the canonical resolver EXACTLY (NFR1 acceptance bar).
$roomRow = fetch_room_by_slug($slug);
$canon = room_stay_quote((int)$roomRow['id'], (float)$roomRow['price_amount'], $ci, $co);
check('quote_stay == room_stay_quote total',  abs(($q['total'] ?? 0) - $canon['total']) < 0.001);
check('quote_stay == room_stay_quote nights', ($q['nights'] ?? -1) === $canon['nights']);

// quote_stay guards
$qBad = assistant_tool_quote_stay(['room' => 'no-such-room-xyz', 'check_in' => $ci, 'check_out' => $co], null);
check('quote_stay: unknown slug → error',    isset($qBad['error']) && ($qBad['need'] ?? '') === 'room');
$qScope = assistant_tool_quote_stay(['room' => $slug, 'check_in' => $ci, 'check_out' => $co], [$venueId + 100000]);
check('quote_stay: out-of-scope → error',    isset($qScope['error']));
$qDate = assistant_tool_quote_stay(['room' => $slug, 'check_in' => $co, 'check_out' => $ci], null);
check('quote_stay: co<=ci → error',          isset($qDate['error']));

// check_availability
$ca = assistant_tool_check_availability(['check_in' => $ci, 'check_out' => $co, 'guests' => 1], null);
check('check_availability: no error',        !isset($ca['error']));
check('check_availability: 3 nights',        ($ca['nights'] ?? 0) === 3);
check('check_availability: lists properties', is_array($ca['properties'] ?? null) && count($ca['properties']) >= 1);
$found = false;
foreach ($ca['properties'] ?? [] as $p) {
    foreach ($p['available_rooms'] ?? [] as $rm) {
        if ($rm['slug'] === $slug) {
            $found = true;
            check('check_availability: room total matches quote', abs($rm['total'] - ($q['total'] ?? -1)) < 0.001);
        }
    }
}
check('check_availability: our room appears', $found);

$caScopedOut = assistant_tool_check_availability(['check_in' => $ci, 'check_out' => $co], []);
check('check_availability: empty scope → no props', ($caScopedOut['properties'] ?? null) === []);

$caBadSlug = assistant_tool_check_availability(['check_in' => $ci, 'check_out' => $co, 'property' => 'no-such-slug'], null);
check('check_availability: bad property slug → error', isset($caBadSlug['error']));

// Every property entry carries the capacity-aware fields (Phase 2 shape).
$hasShape = true;
foreach ($ca['properties'] as $p) {
    if (!array_key_exists('suggested_combinations', $p) || !array_key_exists('max_capacity', $p)) { $hasShape = false; break; }
}
check('check_availability: props carry combos + max_capacity', $hasShape);
foreach ($ca['properties'] as $p) {   // 1-guest search → a single fits → no combos
    check("check_availability: {$p['slug']} no combo for 1 guest", $p['suggested_combinations'] === []);
    break;
}

// ── Combination recommendations for a large party (Phase 2) ───────────────────
// Maya Kobe sleeps 12 across small rooms but has no single ≥ 7 → the tool must
// return a combination whose total equals the sum of quote_stay per room × units.
$mk = db_query("SELECT id, slug FROM venues WHERE slug = 'maya-kobe' AND is_published = TRUE")->fetch();
if ($mk) {
    $ca7 = assistant_tool_check_availability(['check_in' => $ci, 'check_out' => $co, 'guests' => 7, 'property' => 'maya-kobe'], null);
    check('combos: maya-kobe/7 no error', !isset($ca7['error']));
    $mkProp = $ca7['properties'][0] ?? [];
    check('combos: maya-kobe/7 has a suggested combination', !empty($mkProp['suggested_combinations']));
    check('combos: maya-kobe/7 max_capacity >= 7', (int)($mkProp['max_capacity'] ?? 0) >= 7);
    if (!empty($mkProp['suggested_combinations'])) {
        $combo = $mkProp['suggested_combinations'][0];
        check('combos: combination sleeps the party', (int)$combo['sleeps'] >= 7);
        // ONE pricing path: the combined total is the sum of quote_stay totals.
        $sum = 0.0;
        foreach ($combo['rooms'] as $cr) {
            $q = assistant_tool_quote_stay(['room' => $cr['slug'], 'check_in' => $ci, 'check_out' => $co], null);
            $sum += ($q['total'] ?? 0) * (int)$cr['units'];
        }
        check('combos: combined total == Σ quote_stay (units)', abs((float)$combo['total'] - round($sum, 2)) < 0.01);
    }
} else {
    echo "SKIP  combos: maya-kobe not seeded\n";
}

// ── Phase 5: owner-editable prompt composition (rolled back) ──────────────────
// The editable persona/knowledge is APPENDED before the hard rules; an edit can
// shape tone and add facts but never weaken "never invent a price / read-only".
db()->beginTransaction();
try {
    // Empty settings → built-in shape (regression): no editable block injected,
    // the framing runs straight into the Rules section.
    set_setting('ai_persona_staff',   '');
    set_setting('ai_persona_guest',   '');
    set_setting('ai_extra_knowledge', '');
    set_setting('ai_draft_instructions', '');
    $baseStaff = assistant_system_prompt(null, false, 'staff');
    check('phase5: empty settings inject nothing',      !str_contains($baseStaff, 'Tone & voice') && !str_contains($baseStaff, 'Business notes you may use'));
    check('phase5: empty → framing runs into Rules',    str_contains(str_replace("\r", '', $baseStaff), "last night.\n\nRules:"));

    // Persona + knowledge present → both appear, per audience, before the rules.
    set_setting('ai_persona_staff',   'ZZ_STAFF_TONE_MARKER speak plainly');
    set_setting('ai_persona_guest',   'ZZ_GUEST_TONE_MARKER be dreamy');
    set_setting('ai_extra_knowledge', 'ZZ_KNOWLEDGE_MARKER breakfast is included');
    $staff = assistant_system_prompt(null, false, 'staff');
    $guest = assistant_system_prompt(null, false, 'guest');
    check('phase5: staff prompt carries staff persona', str_contains($staff, 'ZZ_STAFF_TONE_MARKER'));
    check('phase5: staff prompt excludes guest persona', !str_contains($staff, 'ZZ_GUEST_TONE_MARKER'));
    check('phase5: guest prompt carries guest persona', str_contains($guest, 'ZZ_GUEST_TONE_MARKER'));
    check('phase5: prompt carries extra knowledge',     str_contains($staff, 'ZZ_KNOWLEDGE_MARKER'));
    // Load-bearing: hard rules survive an edit AND stay after the editable text.
    check('phase5: hard rules survive an edit',         stripos($staff, 'never invent') !== false || stripos($staff, 'Never invent') !== false);
    check('phase5: persona precedes the Rules block',   strpos($staff, 'ZZ_STAFF_TONE_MARKER') < strpos($staff, "\nRules:"));
    check('phase5: knowledge precedes the Rules block', strpos($staff, 'ZZ_KNOWLEDGE_MARKER') < strpos($staff, "\nRules:"));

    set_setting('ai_draft_instructions', 'ZZ_DRAFT_MARKER sign off warmly');
    check('phase5: draft instructions read back',       assistant_draft_instructions() === 'ZZ_DRAFT_MARKER sign off warmly');
    // Phase 4 × Phase 5: the draft brief folds in the owner's house style.
    $briefStyled = assistant_build_draft_brief(['guest_name' => 'X']);
    check('phase4: brief appends house style',          str_contains($briefStyled, 'ZZ_DRAFT_MARKER') && str_contains($briefStyled, 'House drafting style'));
} finally {
    db()->rollBack();
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
