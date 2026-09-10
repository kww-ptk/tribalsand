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

// System-awareness tools (Phases B, D, F–H) — off by default, added by $withFacts.
$defsFacts = assistant_tool_definitions(false, true);
$factNames = array_map(fn($t) => $t['name'], $defsFacts);
$expectFacts = ['property_facts', 'whats_on', 'list_activities', 'menu_details', 'sustainability_facts',
                'convert_currency', 'list_services', 'find_next_availability'];
check('facts tools absent by default',       count(array_intersect($expectFacts, $names)) === 0);
check('withFacts adds the fact tools',       count($defsFacts) === 11 && count(array_intersect($expectFacts, $factNames)) === 8);
check('rag + facts → 12 tools',              count(assistant_tool_definitions(true, true)) === 12);
foreach ($defsFacts as $t) {
    check("fact tool {$t['name']} object schema", (($t['input_schema']['type'] ?? '') === 'object'));
}
// Staff-only ops tools (Phase I) — a separate flag; never in the facts/guest set.
$staffOps = ['occupancy_report', 'daily_operations'];
$defsStaff = assistant_tool_definitions(false, true, true);
$staffNames = array_map(fn($t) => $t['name'], $defsStaff);
check('staff ops absent without flag',       count(array_intersect($staffOps, $factNames)) === 0);
check('withStaffOps adds the two ops tools', count($defsStaff) === 13 && count(array_intersect($staffOps, $staffNames)) === 2);
check('all flags → 14 tools',                count(assistant_tool_definitions(true, true, true)) === 14);

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
// Phase C: graceful over-capacity guidance is always in the base prompt.
check('prompt: max-capacity graceful rule',  str_contains($sys, 'max_capacity') && stripos($sys, 'largest group') !== false);
// Phases B & D: fact tools are only described when enabled.
$sysFacts = assistant_system_prompt(null, false, 'staff', true);
check('prompt: facts tools off by default',  !str_contains($sys, 'property_facts') && !str_contains($sys, 'whats_on') && !str_contains($sys, 'list_activities'));
check('prompt: names facts tools when on',   str_contains($sysFacts, 'property_facts') && str_contains($sysFacts, 'whats_on')
                                             && str_contains($sysFacts, 'list_activities') && str_contains($sysFacts, 'menu_details') && str_contains($sysFacts, 'sustainability_facts')
                                             && str_contains($sysFacts, 'convert_currency') && str_contains($sysFacts, 'list_services') && str_contains($sysFacts, 'find_next_availability'));
// Staff-ops tools are gated separately and NEVER leak into the guest/facts prompt.
$sysStaff = assistant_system_prompt(null, false, 'staff', true, true);
$sysGuest = assistant_system_prompt(null, false, 'guest', true, false);
check('prompt: staff ops only with the flag', !str_contains($sysFacts, 'occupancy_report') && str_contains($sysStaff, 'occupancy_report') && str_contains($sysStaff, 'daily_operations'));
check('prompt: guest never sees staff ops',   !str_contains($sysGuest, 'occupancy_report') && !str_contains($sysGuest, 'daily_operations'));

// convert_currency — validation + identity are pure (no rate lookup / no DB).
$ccNoAmt = assistant_tool_convert_currency(['from' => 'USD', 'to' => 'KES']);
check('convert_currency: needs amount',      isset($ccNoAmt['error']) && ($ccNoAmt['need'] ?? '') === 'amount');
$ccSame = assistant_tool_convert_currency(['amount' => 100, 'from' => 'USD', 'to' => 'USD']);
check('convert_currency: same → identity',   ($ccSame['to']['amount'] ?? null) == 100.0 && ($ccSame['to']['currency'] ?? '') === 'USD');
$ccUns = assistant_tool_convert_currency(['amount' => 100, 'from' => 'USD', 'to' => 'ZZZ']);
check('convert_currency: bad target → error', isset($ccUns['error']) && ($ccUns['need'] ?? '') === 'currency');

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

// ── Phase B: property_facts (read-only structured facts) ─────────────────────
$venueSlug = (string) db_query('SELECT slug FROM venues WHERE id = :id', [':id' => $venueId])->fetchColumn();
$pf = assistant_tool_property_facts([], null);
check('property_facts: returns properties',  !empty($pf['properties']));
$pfV = null;
foreach ($pf['properties'] as $p) { if ($p['slug'] === $venueSlug) { $pfV = $p; break; } }
check('property_facts: includes our venue',  $pfV !== null);
if ($pfV) {
    check('property_facts: has room inventory', is_array($pfV['rooms']) && array_key_exists('total_rooms', $pfV) && array_key_exists('max_occupancy', $pfV));
    // Structural: property_facts must NEVER carry a nightly price/total.
    $noPrice = true;
    foreach ($pfV['rooms'] as $rm) { if (array_key_exists('total', $rm) || array_key_exists('price', $rm) || array_key_exists('price_per_night', $rm)) { $noPrice = false; break; } }
    check('property_facts: carries no nightly price', $noPrice);
}
$pfScoped = assistant_tool_property_facts([], [$venueId]);
check('property_facts: scoped to one',       count($pfScoped['properties'] ?? []) === 1);
$pfEmpty = assistant_tool_property_facts([], []);
check('property_facts: empty scope → none',  ($pfEmpty['properties'] ?? null) === [] && isset($pfEmpty['note']));
$pfBad = assistant_tool_property_facts(['property' => 'no-such-slug-xyz'], null);
check('property_facts: bad slug → error',    isset($pfBad['error']) && ($pfBad['need'] ?? '') === 'property');

// Phase E cross-surface consistency (RAG↔DB spirit): the "biggest group" the
// facts tool reports must equal the max_capacity the availability tool returns
// for an all-free window — the two AI surfaces must never disagree.
$caV = null;
foreach ($ca['properties'] as $p) { if ($p['slug'] === $venueSlug) { $caV = $p; break; } }
if ($pfV && $caV && $pfV['max_occupancy'] !== null) {
    check('facts/availability agree on max party', (int)$pfV['max_occupancy'] === (int)$caV['max_capacity']);
}

// ── Phase D: whats_on (offers / menus / dining) ──────────────────────────────
$wo = assistant_tool_whats_on([], null);
check('whats_on: no error',                  !isset($wo['error']));
check('whats_on: has the three sections',    is_array($wo['offers'] ?? null) && is_array($wo['menus'] ?? null) && isset($wo['table_reservations']));
check('whats_on: reservable flag is bool',   is_bool($wo['table_reservations']['available'] ?? null));
$woScoped = assistant_tool_whats_on([], []);   // empty scope drops venue-bound menus/dining, keeps site-wide offers
check('whats_on: empty scope → no dining',   ($woScoped['table_reservations']['properties'] ?? null) === []);

// ── Phase F: list_activities (tours catalogue, read-only, site-wide) ─────────
$act = assistant_tool_list_activities([]);
check('list_activities: no error',           !isset($act['error']) && array_key_exists('activities', $act));
$actLoc = assistant_tool_list_activities(['location' => 'watamu']);
check('list_activities: location filter ok', is_array($actLoc['activities'] ?? null));
// Structural: an activity carries a display price string or null, never a computed nightly total.
$actShape = true;
foreach (($act['activities'] ?? []) as $a) {
    if (!array_key_exists('activity', $a) || !array_key_exists('price', $a) || array_key_exists('total', $a)) { $actShape = false; break; }
}
check('list_activities: expected item shape', $actShape);

// ── Phase F: menu_details ────────────────────────────────────────────────────
$mdNeed = assistant_tool_menu_details([], null);
check('menu_details: needs a slug',          isset($mdNeed['error']) && ($mdNeed['need'] ?? '') === 'menu');
if (function_exists('menus_supported') && menus_supported()) {
    $anyMenu = db_query("SELECT slug FROM menus WHERE is_published = TRUE ORDER BY sort_order, id LIMIT 1")->fetchColumn();
    if ($anyMenu) {
        $md = assistant_tool_menu_details(['menu' => (string)$anyMenu], null);
        check('menu_details: resolves a menu',   !isset($md['error']) && is_array($md['sections'] ?? null) && ($md['currency'] ?? '') === 'KES');
        // No nightly stay price leaks into a menu item.
        $mdOk = true;
        foreach (($md['sections'] ?? []) as $s) { foreach ($s['items'] as $it) { if (array_key_exists('total', $it)) { $mdOk = false; break 2; } } }
        check('menu_details: items carry no stay total', $mdOk);
    } else {
        echo "SKIP  menu_details (no published menu seeded)\n";
    }
    $mdBad = assistant_tool_menu_details(['menu' => 'no-such-menu-xyz'], null);
    check('menu_details: bad slug → error',      isset($mdBad['error']));
} else {
    echo "SKIP  menu_details (menus table absent)\n";
}

// ── Phase G: sustainability_facts (live figures; fallback-safe) ──────────────
$sf = assistant_tool_sustainability_facts();
check('sustainability_facts: returns metrics', is_array($sf['metrics'] ?? null) && count($sf['metrics']) >= 1);
$sfShape = true;
foreach ($sf['metrics'] as $m) { if (!array_key_exists('metric', $m) || !array_key_exists('value', $m) || !is_numeric($m['value'])) { $sfShape = false; break; } }
check('sustainability_facts: numeric values',  $sfShape);

// ── Phase H: list_services + find_next_availability + convert (with rates) ────
$svc = assistant_tool_list_services();
check('list_services: no error',             !isset($svc['error']) && array_key_exists('services', $svc));

// A real conversion needs the live rate table; USD→KES should convert both ways.
$cc = assistant_tool_convert_currency(['amount' => 100, 'from' => 'USD', 'to' => 'KES']);
check('convert_currency: USD→KES converts',  !isset($cc['error']) && ($cc['to']['currency'] ?? '') === 'KES' && ($cc['to']['amount'] ?? 0) > 0 && ($cc['from']['amount'] ?? 0) == 100.0);

// find_next_availability: our room's venue is free far out, so a short scan from
// that date must find something; a scoped-out account finds nothing.
$fn = assistant_tool_find_next_availability(['guests' => 1, 'nights' => 2, 'earliest' => $ci, 'search_days' => 3, 'property' => $venueSlug], null);
check('find_next_availability: finds a date', ($fn['found'] ?? false) === true && !empty($fn['properties']));
$fnScoped = assistant_tool_find_next_availability(['guests' => 1, 'nights' => 2, 'earliest' => $ci, 'search_days' => 2], []);
check('find_next_availability: empty scope → none', ($fnScoped['found'] ?? true) === false);
$fnBadDate = assistant_tool_find_next_availability(['earliest' => 'not-a-date']);
check('find_next_availability: bad date → error', isset($fnBadDate['error']));

// ── Phase I: staff-only occupancy_report + daily_operations ──────────────────
if (function_exists('bookings_supported') && bookings_supported()) {
    $orBad = assistant_tool_occupancy_report(['from' => '2099-02-01', 'to' => '2099-01-01'], null);
    check('occupancy_report: to<from → error',   isset($orBad['error']));
    $or = assistant_tool_occupancy_report(['from' => '2099-01-01', 'to' => '2099-12-31'], null);
    check('occupancy_report: returns shape',     !isset($or['error']) && array_key_exists('currencies', $or) && isset($or['occupancy']['pct']));
    $orScope = assistant_tool_occupancy_report(['from' => '2099-01-01', 'to' => '2099-12-31', 'property' => 'no-such-prop'], null);
    check('occupancy_report: bad property → error', isset($orScope['error']));
} else {
    echo "SKIP  occupancy_report (bookings ledger absent)\n";
}
$do = assistant_tool_daily_operations(['date' => $ci], null);
check('daily_operations: shape',             !isset($do['error']) && is_array($do['arrivals'] ?? null) && is_array($do['departures'] ?? null) && isset($do['reservations']));
$doScoped = assistant_tool_daily_operations(['date' => $ci], []);
check('daily_operations: empty scope → empty', ($doScoped['arrivals'] ?? null) === [] && ($doScoped['departures'] ?? null) === []);
$doBad = assistant_tool_daily_operations(['date' => 'nope'], null);
check('daily_operations: bad date → error',  isset($doBad['error']));

// ── Phase E golden invariant: AI numbers == canonical resolver, every property ─
// The acceptance bar — an AI quote must equal the booking widget's — asserted
// across ALL properties/rooms the availability tool surfaced, not just one.
$goldenOk = true; $goldenChecked = 0;
foreach ($ca['properties'] as $p) {
    foreach ($p['available_rooms'] ?? [] as $rm) {
        $qq = assistant_tool_quote_stay(['room' => $rm['slug'], 'check_in' => $ci, 'check_out' => $co], null);
        if (isset($qq['total'])) {
            $goldenChecked++;
            if (abs((float)$qq['total'] - (float)$rm['total']) > 0.001) { $goldenOk = false; break 2; }
        }
    }
}
check('golden: every room total == quote_stay', $goldenOk && $goldenChecked >= 1);

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
