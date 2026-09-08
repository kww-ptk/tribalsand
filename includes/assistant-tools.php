<?php
declare(strict_types=1);
/**
 * Assistant tool layer — thin, READ-ONLY wrappers over the app's existing
 * availability/price helpers, plus their JSON-schema definitions and the
 * system prompt. This is the whole "truth" side of the assistant: the model
 * only picks a tool and phrases the answer; every number originates here.
 *
 * Hard rules (inherited from the codebase and the plan):
 *   · READ-ONLY. Nothing here writes, holds, or mutates. The model can quote;
 *     a human/existing hold flow confirms.
 *   · ONE pricing path. Quotes come from room_stay_quote() (→ rates_nightly_map)
 *     only — never a second nightly loop. nights===0 means "not a quote" and is
 *     surfaced as a structured error, never a $0 stay.
 *   · Nairobi-local dates. date() is already Africa/Nairobi (db.php), so "today"
 *     here matches the DB and the booking widget.
 *   · Scope by role. When a venue scope (admin_venue_ids()) is passed, every
 *     tool is filtered to it, exactly like the rest of admin. null = all venues.
 *   · Fail honest. Ambiguous/invalid dates return {error, need} so the model
 *     asks a clarifying question instead of guessing.
 */
require_once __DIR__ . '/db.php';           // ts_search_availability(), room_stay_quote(), fetch_room_by_slug(), find_available_unit()
require_once __DIR__ . '/rates.php';        // rates_window_ymd()
require_once __DIR__ . '/assistant-rag.php'; // rag_supported(), rag_search() — descriptive layer (Phase 2)

/** Today, Nairobi-local (date() is set to Africa/Nairobi in db.php). */
function assistant_today_ymd(): string {
    return date('Y-m-d');
}

/**
 * Tool definitions handed to the model. Kept deliberately small — three
 * lookups cover the Phase-1 use case ("what's free for N pax X→Y and the
 * price"). input_schema is JSON Schema; dates are strict YYYY-MM-DD so the
 * model resolves relative phrases ("next weekend") into concrete dates itself,
 * anchored to the `today` we give it in the system prompt.
 */
function assistant_tool_definitions(bool $withRag = false): array {
    $date = ['type' => 'string', 'description' => 'Calendar date in strict YYYY-MM-DD format, Africa/Nairobi local.'];
    $tools = [
        [
            'name'        => 'list_properties',
            'description' => 'List the bookable properties (villas) AND the individual rooms within each, all with their URL slugs. Use this to map any name the guest mentions — a property OR a specific room — to the right slug: a property slug narrows check_availability, a room slug is what quote_stay needs. Call it whenever you are unsure whether a name is a property or a room, before telling the guest something is unavailable.',
            'input_schema' => ['type' => 'object', 'properties' => (object)[], 'required' => []],
        ],
        [
            'name'        => 'check_availability',
            'description' => 'Check which rooms/villas are FREE for a date range and party size, with live prices. Returns one entry per property with its available rooms and the total for the stay. Covers all properties at once — use it for "anything free for 4 in December?" as well as a single property.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'check_in'  => $date + ['description' => 'Arrival date (YYYY-MM-DD).'],
                    'check_out' => $date + ['description' => 'Departure/checkout date (YYYY-MM-DD), the morning after the last night. Must be after check_in.'],
                    'guests'    => ['type' => 'integer', 'minimum' => 1, 'description' => 'Number of guests (party size). Default 1 if the guest did not say.'],
                    'property'  => ['type' => 'string', 'description' => 'OPTIONAL property slug to restrict the search to one property. Omit to search every property.'],
                ],
                'required' => ['check_in', 'check_out'],
            ],
        ],
        [
            'name'        => 'quote_stay',
            'description' => 'Get the exact price and availability for ONE specific room/villa over a date range. Use when the guest names a room and dates. Returns nights, nightly rate and total, and whether it is free. This is the same figure the booking page shows.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'room'      => ['type' => 'string', 'description' => 'The room/villa URL slug (from list_properties or check_availability).'],
                    'check_in'  => $date + ['description' => 'Arrival date (YYYY-MM-DD).'],
                    'check_out' => $date + ['description' => 'Departure/checkout date (YYYY-MM-DD), after check_in.'],
                ],
                'required' => ['room', 'check_in', 'check_out'],
            ],
        ],
    ];

    // Descriptive layer (Phase 2). Only offered when RAG is configured — a
    // deploy without pgvector/embeddings keeps the three factual tools and
    // simply can't answer prose questions, rather than exposing a dead tool.
    if ($withRag) {
        $tools[] = [
            'name'        => 'search_property_info',
            'description' => 'Search the property, room, and activity DESCRIPTIONS for non-price information — what a villa or room is like, amenities and features, house rules, check-in/out details, Wi-Fi, area/location guides, cancellation and other policies, FAQs, nearby activities and tours, and sustainability initiatives. Use this for any "what/how/tell me about/is there…" question that is NOT about live availability or price. It returns short text passages; base your answer only on what comes back, and if nothing relevant is returned, say you do not have that information. Never use it to state prices or availability — use quote_stay / check_availability for those.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'The information need, as a short natural-language phrase (e.g. "Zuri villa amenities", "cancellation policy", "activities near Watamu").'],
                ],
                'required' => ['query'],
            ],
        ];
    }
    return $tools;
}

/** The system prompt: role, the hard rules, and today's date for relative-date resolution. */
function assistant_system_prompt(?array $venueScope, bool $withRag = false): string {
    $today = assistant_today_ymd();
    $dow   = date('l');   // e.g. "Monday"
    $scopeLine = $venueScope === null
        ? 'You can see every property.'
        : 'You are scoped to this account\'s assigned properties only; the tools already filter to them, so never claim to know about others.';
    $ragLine = $withRag
        ? "\n- For DESCRIPTIVE questions (what a property/room is like, amenities, house rules, check-in/out, Wi-Fi, area guides, policies, FAQs, nearby activities, sustainability), call search_property_info and answer ONLY from what it returns. If it returns nothing relevant, say you don't have that information rather than guessing. Never use it for prices or availability — those come from the factual tools. It is fine to combine: use search_property_info for the description and quote_stay/check_availability for the numbers."
        : '';
    return <<<SYS
You are the Tribal Sand availability & price assistant, used by front-desk and management staff.

Your job: answer questions about what rooms/villas are free, what they cost, and — where the tools allow — what the properties and activities are like, by calling the tools. You do NOT know availability or prices yourself — always get them from a tool. Never invent a date, a price, or an availability status.

Today is {$dow}, {$today} (Africa/Nairobi). Resolve relative dates ("tonight", "this weekend", "next Friday", "in December") against today, and pass concrete YYYY-MM-DD dates to the tools. Check-out is the morning after the last night.

Rules:
- A name or slug the guest gives may be a whole PROPERTY or a specific ROOM. A room slug (e.g. "zuri-maji") goes to quote_stay; a property slug narrows check_availability. If you are unsure which a name is, call list_properties first to resolve it — it lists every property and its rooms with slugs. NEVER tell the guest a room "doesn't exist" or "is the wrong name" before checking list_properties; a slug like "zuri-maji" is usually a valid room, not a mistake.
- If the guest's dates or party size are missing or ambiguous, ask ONE short clarifying question instead of guessing. A weekend means Friday check-in to Sunday check-out unless told otherwise.
- Prices come only from the tools. Quote the exact figure a tool returns, with its currency. Do not do your own arithmetic on nightly rates — the tool already totals the stay.
- You can quote and inform only. You cannot book, hold, or change anything — if the guest wants to book, tell staff to use the normal booking/hold flow.
- If a tool returns an error, explain briefly and, if it needs clarification, ask for it.
- {$scopeLine}{$ragLine}
- When you mention a room or property in your answer, use its friendly name (the "room"/"property" field the tool returns), not the URL slug the guest typed.
- Be concise and practical. Prefer a short sentence plus the key figures. Amounts are per the currency the tool returns (USD shown as \$, others as the code).
SYS;
}

/**
 * Dispatch a tool call by name. Always returns a JSON-able array; on bad input
 * it returns ['error'=>…] (optionally ['need'=>…]) so the model can recover —
 * it never throws. $venueScope: null = all venues (owner); array of ids = limit.
 */
function assistant_run_tool(string $name, array $args, ?array $venueScope): array {
    switch ($name) {
        case 'list_properties':  return assistant_tool_list_properties($venueScope);
        case 'check_availability': return assistant_tool_check_availability($args, $venueScope);
        case 'quote_stay':       return assistant_tool_quote_stay($args, $venueScope);
        case 'search_property_info': return assistant_tool_search_info($args, $venueScope);
        default:                 return ['error' => 'Unknown tool "' . $name . '".'];
    }
}

/** True when scope is null (owner/all) or the id is in the allowed set. */
function assistant_in_scope(?array $venueScope, int $venueId): bool {
    return $venueScope === null || in_array($venueId, $venueScope, true);
}

/** list_properties: published, in-scope venues, each with its published rooms (name + slug). */
function assistant_tool_list_properties(?array $venueScope): array {
    $venues = db_query('SELECT id, name, slug FROM venues WHERE is_published = TRUE ORDER BY sort_order ASC, name ASC')->fetchAll();
    $out = [];
    foreach ($venues as $v) {
        if (!assistant_in_scope($venueScope, (int)$v['id'])) continue;
        $rooms = db_query(
            'SELECT name, slug, capacity, is_entire_place FROM rooms
              WHERE venue_id = :vid AND is_published = TRUE
              ORDER BY is_entire_place ASC, sort_order ASC',
            [':vid' => (int)$v['id']]
        )->fetchAll();
        $rlist = [];
        foreach ($rooms as $r) {
            $rlist[] = [
                'room'           => $r['name'],
                'slug'           => $r['slug'],   // ← the slug quote_stay expects
                'sleeps'         => (int)($r['capacity'] ?? 0) ?: null,
                'whole_property' => !empty($r['is_entire_place']),
            ];
        }
        $out[] = ['property' => $v['name'], 'slug' => $v['slug'], 'rooms' => $rlist];
    }
    if (!$out) return ['properties' => [], 'note' => 'No bookable properties are available for this account.'];
    return ['properties' => $out];
}

/**
 * search_property_info: descriptive (non-price) retrieval over embedded prose.
 * Thin wrapper over rag_search() — READ-ONLY, scope-filtered, and structurally
 * incapable of returning a price (the embeddings table holds no figures).
 */
function assistant_tool_search_info(array $args, ?array $venueScope): array {
    $q = trim((string)($args['query'] ?? ''));
    if ($q === '') return ['error' => 'A search query is required.', 'need' => 'query'];
    return rag_search($q, 5, $venueScope);
}

/** Validate a check_in/check_out pair. Returns [ci, co] or ['error'=>…,'need'=>…]. */
function assistant_validate_window(array $args): array {
    $ciRaw = trim((string)($args['check_in'] ?? ''));
    $coRaw = trim((string)($args['check_out'] ?? ''));
    if ($ciRaw === '' || $coRaw === '') {
        return ['error' => 'Both check_in and check_out are required.', 'need' => 'dates'];
    }
    $ci = rates_window_ymd($ciRaw);
    $co = rates_window_ymd($coRaw);
    if ($ci === null || $co === null) {
        return ['error' => 'Dates must be valid and formatted YYYY-MM-DD.', 'need' => 'dates'];
    }
    if ($ci >= $co) {
        return ['error' => 'check_out must be after check_in (check-out is the morning after the last night).', 'need' => 'dates'];
    }
    return [$ci, $co];
}

/**
 * check_availability: cross-property free rooms + live prices for a window and
 * party size. Wraps ts_search_availability() and trims its rich rows down to a
 * compact, model-friendly shape. Scope-filtered; optional single-property slug.
 */
function assistant_tool_check_availability(array $args, ?array $venueScope): array {
    $win = assistant_validate_window($args);
    if (isset($win['error'])) return $win;
    [$ci, $co] = $win;

    $guests = (int)($args['guests'] ?? 1);
    if ($guests < 1) $guests = 1;
    $onlySlug = trim((string)($args['property'] ?? ''));

    $results = ts_search_availability($ci, $co, $guests);   // single canonical availability path

    $props = [];
    foreach ($results as $r) {
        $v = $r['venue'];
        if (!assistant_in_scope($venueScope, (int)$v['id'])) continue;
        if ($onlySlug !== '' && $v['slug'] !== $onlySlug) continue;

        $rooms = [];
        foreach ($r['rooms'] as $room) {
            $rooms[] = [
                'room'            => $room['name'],
                'slug'            => $room['slug'],
                'whole_property'  => (bool)$room['entire'],
                'sleeps'          => $room['capacity'] ?: null,
                'nights'          => $room['nights'],
                'total'           => $room['total'],
                'price_per_night' => $room['nights'] > 0 ? round($room['total'] / $room['nights'], 2) : null,
                'currency'        => $room['currency'],
            ];
        }
        $props[] = [
            'property'        => $v['name'],
            'slug'            => $v['slug'],
            'available_rooms' => $rooms,
            'from'            => $r['from'],
            'currency'        => $r['currency'],
        ];
    }

    if ($onlySlug !== '' && !$props) {
        return ['error' => 'No property matches the slug "' . $onlySlug . '" — it may actually be a ROOM slug rather than a property. Call list_properties to resolve it, then use quote_stay with the room slug (or check_availability with the correct property slug).', 'need' => 'property'];
    }

    $anyRoom = false;
    foreach ($props as $p) { if ($p['available_rooms']) { $anyRoom = true; break; } }

    return [
        'check_in'   => $ci,
        'check_out'  => $co,
        'nights'     => (int)((strtotime($co) - strtotime($ci)) / 86400),
        'guests'     => $guests,
        'properties' => $props,
        'note'       => $anyRoom ? null : 'Nothing is free for those dates and party size.',
    ];
}

/**
 * quote_stay: exact availability + price for one room over a window. Wraps
 * room_stay_quote() (the single resolver) and find_available_unit(). Rejects an
 * unparseable window (nights===0) rather than quote a free stay.
 */
function assistant_tool_quote_stay(array $args, ?array $venueScope): array {
    $slug = trim((string)($args['room'] ?? ''));
    if ($slug === '') return ['error' => 'A room slug is required.', 'need' => 'room'];

    $win = assistant_validate_window($args);
    if (isset($win['error'])) return $win;
    [$ci, $co] = $win;

    $room = fetch_room_by_slug($slug);
    if (!$room) {
        return ['error' => 'No published room matches the slug "' . $slug . '". Call list_properties or check_availability for valid slugs.', 'need' => 'room'];
    }
    if (!assistant_in_scope($venueScope, (int)$room['venue_id'])) {
        return ['error' => 'That room belongs to a property this account cannot see.'];
    }

    $quote = room_stay_quote((int)$room['id'], (float)$room['price_amount'], $ci, $co);
    if (($quote['nights'] ?? 0) <= 0) {
        // Not a quote — never present this as a $0 stay.
        return ['error' => 'That date range could not be priced. Please confirm the check-in and check-out dates.', 'need' => 'dates'];
    }

    $available = (bool)find_available_unit((int)$room['id'], $ci, $co);
    $venue = db_query('SELECT name, slug FROM venues WHERE id = :id', [':id' => (int)$room['venue_id']])->fetch() ?: [];

    return [
        'property'        => $venue['name'] ?? '',
        'property_slug'   => $venue['slug'] ?? '',
        'room'            => $room['name'],
        'slug'            => $room['slug'],
        'whole_property'  => !empty($room['is_entire_place']),
        'sleeps'          => (int)($room['capacity'] ?? 0) ?: null,
        'check_in'        => $ci,
        'check_out'       => $co,
        'nights'          => $quote['nights'],
        'price_per_night' => round($quote['total'] / $quote['nights'], 2),
        'total'           => $quote['total'],
        'currency'        => $room['price_currency'] ?: 'USD',
        'available'       => $available,
        'note'            => $available ? null : 'Those dates are not free for this room.',
    ];
}
