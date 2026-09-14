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
require_once __DIR__ . '/offers.php';       // fetch_published_offers() — whats_on (Phase D)
require_once __DIR__ . '/menu.php';         // menus_supported() — whats_on (Phase D)
require_once __DIR__ . '/reservations.php'; // reservations_supported(), fetch_reservable_venues() — whats_on (Phase D)
require_once __DIR__ . '/sustainability.php'; // sus_metrics() — sustainability_facts (Phase G)
require_once __DIR__ . '/services.php';     // fetch_service_options() — list_services (Phase H)
require_once __DIR__ . '/bookings.php';     // bookings_in_window/summarize/occupancy — occupancy_report (staff, Phase I)

/** Today, Nairobi-local (date() is set to Africa/Nairobi in db.php). */
function assistant_today_ymd(): string {
    return date('Y-m-d');
}

/**
 * Owner-editable AI settings (Phase 5). These let the owner tune TONE and add
 * BUSINESS FACTS from Admin → AI Assistant, stored in the plain `settings` KV.
 * They are composed into the system prompt as an *appended* block only — the
 * hard safety/pricing rules stay hardcoded and LAST, so no edit here can weaken
 * "ONE pricing path / never invent a price / read-only". Reads are defensive: a
 * settings or DB hiccup yields '' so the assistant still runs on the built-in
 * prompt rather than 500-ing.
 *
 * Keys: ai_persona_staff, ai_persona_guest (tone per audience),
 *       ai_extra_knowledge (freeform business facts), ai_draft_instructions
 *       (how Phase-4 enquiry-thread drafts should read).
 */
function ai_editable_setting(string $key): string {
    try {
        return trim((string) setting($key, ''));
    } catch (\Throwable $e) {
        return '';
    }
}

/** Owner-set tone/voice for the given audience ('staff'|'guest'); '' when unset. */
function assistant_persona(string $audience): string {
    return ai_editable_setting($audience === 'guest' ? 'ai_persona_guest' : 'ai_persona_staff');
}

/** Owner-set freeform business facts appended as background; '' when unset. */
function assistant_extra_knowledge(): string {
    return ai_editable_setting('ai_extra_knowledge');
}

/** Owner-set drafting guidance for enquiry-thread replies (Phase 4); '' when unset. */
function assistant_draft_instructions(): string {
    return ai_editable_setting('ai_draft_instructions');
}

/**
 * Assemble the user-turn brief the model drafts an enquiry reply from (Phase 4).
 * Pure string-building over a submission row (no DB, no model) so it is unit-
 * testable. The model still resolves availability/prices from the READ-ONLY
 * tools — nothing asserted here is a price. Appends the owner's editable draft
 * instructions (Phase 5) when set. Used by api/assistant-draft.php.
 */
function assistant_build_draft_brief(array $sub): string {
    $name     = trim((string)($sub['guest_name'] ?? '')) ?: 'the guest';
    $property = trim((string)($sub['venue_name'] ?? ''));
    $room     = trim((string)($sub['room_name'] ?? ''));
    $ci       = trim((string)($sub['check_in'] ?? ''));
    $co       = trim((string)($sub['check_out'] ?? ''));
    $adults   = (int)($sub['guests_adults'] ?? 0);
    $children = (int)($sub['guests_children'] ?? 0);
    $message  = trim((string)($sub['message'] ?? ''));

    $want = $room !== '' && $property !== '' ? "$room at $property"
          : ($room !== '' ? $room : ($property !== '' ? $property : 'not specified — consider all properties'));
    $dates = ($ci !== '' && $co !== '') ? "$ci to $co (check-out $co)" : 'not specified — ask or suggest options';
    $party = $adults > 0 || $children > 0
        ? ($adults . ' adult' . ($adults === 1 ? '' : 's') . ($children ? ', ' . $children . ' child' . ($children === 1 ? '' : 'ren') : ''))
        : 'not specified';

    $lines = [
        'Draft a warm, ready-to-send email reply to the booking enquiry below. Use the tools to check live availability and prices for the requested dates and party size, and propose the best-fit option — including a sensible multi-room combination when no single room seats the party — with the exact prices the tools return. If the requested dates are not free, offer the nearest alternative you can verify with the tools. Never invent a price or an availability. Write only the reply itself, from greeting to sign-off, ready for a staff member to review and send — no subject line, and no [bracketed] placeholders.',
        '',
        'Enquiry details:',
        '- Guest name: ' . $name,
        '- Interested in: ' . $want,
        '- Dates: ' . $dates,
        '- Party: ' . $party,
    ];
    if ($message !== '') {
        $lines[] = '- Their message: "' . $message . '"';
    }

    $extra = assistant_draft_instructions();     // owner-editable house style (Phase 5)
    if ($extra !== '') {
        $lines[] = '';
        $lines[] = 'House drafting style to follow:';
        $lines[] = $extra;
    }

    return implode("\n", $lines);
}

/**
 * Tool definitions handed to the model. Kept deliberately small — three
 * lookups cover the Phase-1 use case ("what's free for N pax X→Y and the
 * price"). input_schema is JSON Schema; dates are strict YYYY-MM-DD so the
 * model resolves relative phrases ("next weekend") into concrete dates itself,
 * anchored to the `today` we give it in the system prompt.
 */
function assistant_tool_definitions(bool $withRag = false, bool $withFacts = false, bool $withStaffOps = false): array {
    $date = ['type' => 'string', 'description' => 'Calendar date in strict YYYY-MM-DD format, Africa/Nairobi local.'];
    $tools = [
        [
            'name'        => 'list_properties',
            'description' => 'List the bookable properties (villas) AND the individual rooms within each, all with their URL slugs. Use this to map any name the guest mentions — a property OR a specific room — to the right slug: a property slug narrows check_availability, a room slug is what quote_stay needs. Call it whenever you are unsure whether a name is a property or a room, before telling the guest something is unavailable.',
            'input_schema' => ['type' => 'object', 'properties' => (object)[], 'required' => []],
        ],
        [
            'name'        => 'check_availability',
            'description' => 'Check which rooms/villas are FREE for a date range and party size, with live prices. Returns one entry per property with its available single rooms, the total for the stay, AND — for a party no single room can seat — one or more suggested multi-room COMBINATIONS (which rooms, per-room and combined price, and how many it sleeps), plus the property\'s max_capacity for those dates. Use it for "anything free for 4 in December?", a single property, and larger groups ("where can 7 people stay?") alike. Present only the combinations the tool returns; never invent one.',
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

    // System-awareness layer (Phases B & D). DB-only, no external dependency, so
    // it is always safe to expose; still off by default to preserve the Phase-1
    // three-tool shape for callers that pass no flags.
    if ($withFacts) {
        $tools[] = [
            'name'        => 'property_facts',
            'description' => 'Get the STRUCTURED facts about a property (or every property): how many rooms/suites it has, how many are individually bookable, the largest party it can host (max occupancy), the security-deposit amount, checkout time, Wi-Fi note, and location/address. Use this for "how many bedrooms does X have?", "how big a group fits at X?", "what\'s the deposit?", "where is it?", "what time is checkout?". These are exact stored facts — do NOT guess a room count, an occupancy, or a deposit. It returns NO nightly prices or live availability — use quote_stay / check_availability for those.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'property' => ['type' => 'string', 'description' => 'OPTIONAL property slug to limit to one property. Omit for every property.'],
                ],
                'required' => [],
            ],
        ];
        $tools[] = [
            'name'        => 'whats_on',
            'description' => 'List what is currently ON across Tribal Sand: live special offers/deals, published restaurant menus, and whether tables can be reserved (and where). Use this for "any offers/deals right now?", "do you have a restaurant / can I see the menu?", "can I book a table?". Returns only what is actually published/current — never invent an offer, a menu, or a discount. It carries NO nightly room prices — use quote_stay / check_availability for stays.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'property' => ['type' => 'string', 'description' => 'OPTIONAL property slug to limit menus/dining to one property. Site-wide offers are always included. Omit for everything.'],
                ],
                'required' => [],
            ],
        ];
        $tools[] = [
            'name'        => 'list_activities',
            'description' => 'List the activities, tours and experiences Tribal Sand offers, with their price (as published), category, area, duration and a short summary. Use this for "what is there to do?", "any dhow trips / safaris?", "activities near Watamu?", "how much is the kitesurfing?". Prices are the exact published figures — never invent one. Optionally filter by area or category.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'location' => ['type' => 'string', 'description' => 'OPTIONAL area filter: watamu | kilifi | vipingo | all. Omit for every area.'],
                    'category' => ['type' => 'string', 'description' => 'OPTIONAL category slug to filter by (from a previous list_activities result). Omit for all categories.'],
                ],
                'required' => [],
            ],
        ];
        $tools[] = [
            'name'        => 'menu_details',
            'description' => 'Get the actual dishes on a restaurant menu — item names, prices (KES), dietary/other badges and descriptions, grouped by section. Use this for "what\'s on the breakfast menu?", "do you have vegan / gluten-free options?", "how much is the lobster?". Badges are exact and DISTINCT: "vegan" (no animal products) is NOT the same as "veg" (vegetarian — may contain dairy or eggs); a dish is only vegan if it carries the "vegan" badge. When a guest asks for vegan, vegetarian, gluten-free, etc., list ONLY the dishes that carry that exact badge — never infer a dish is vegan/vegetarian from its name, description or ingredients (e.g. a creamy or cheese pasta is not vegan). Give it a menu slug or a property slug. Prices are the exact stored figures — never invent one.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'menu'     => ['type' => 'string', 'description' => 'OPTIONAL menu slug (from whats_on). Takes precedence over property.'],
                    'property' => ['type' => 'string', 'description' => 'OPTIONAL property slug — uses that property\'s first published menu. Give either this or menu.'],
                ],
                'required' => [],
            ],
        ];
        $tools[] = [
            'name'        => 'sustainability_facts',
            'description' => 'Get Tribal Sand\'s current sustainability figures — solar energy generated, CO₂ avoided, beach waste collected, desalinated water, etc., each with its live value, unit and a short note. Use this for "how green are you?", "how much solar do you produce?", "what do you do for the environment?" (numbers only — describe initiatives with search_property_info). These are the live published figures; never invent one.',
            'input_schema' => ['type' => 'object', 'properties' => (object)[], 'required' => []],
        ];
        $tools[] = [
            'name'        => 'convert_currency',
            'description' => 'Convert a price you ALREADY have (from quote_stay / check_availability / another tool) into a different currency for display, using the live exchange rates. Use it only when the guest asks to see a figure in another currency (e.g. "how much is that in KES/EUR?"). This is display-only: never treat the converted number as the booking price — the booking is always in the room\'s own currency. Do NOT invent a rate or do the maths yourself; call this.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'amount' => ['type' => 'number', 'description' => 'The amount to convert (a figure a tool already returned).'],
                    'from'   => ['type' => 'string', 'description' => 'The currency the amount is in (ISO code, e.g. USD, KES).'],
                    'to'     => ['type' => 'string', 'description' => 'The currency to convert into (ISO code, e.g. KES, EUR, GBP).'],
                ],
                'required' => ['amount', 'from', 'to'],
            ],
        ];
        $tools[] = [
            'name'        => 'list_services',
            'description' => 'List the paid on-property services (transfers, laundry) and their configured amounts. Use for "do you do airport transfers?", "is there laundry and how much?". These are convenience services arranged at the property, not part of the room rate; amounts are as configured. Never invent a service or a price.',
            'input_schema' => ['type' => 'object', 'properties' => (object)[], 'required' => []],
        ];
        $tools[] = [
            'name'        => 'find_next_availability',
            'description' => 'Find the SOONEST dates something is free for a party, by scanning forward from a start date. Use when the guest is flexible ("when is Zuri next available?", "what\'s the earliest we could come for 4?"). Returns the first date range with availability and what is free then, or reports nothing was free within the window searched. Prices/availability come from the same live path as check_availability — never invent a date.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'guests'      => ['type' => 'integer', 'minimum' => 1, 'description' => 'Party size. Default 2.'],
                    'nights'      => ['type' => 'integer', 'minimum' => 1, 'description' => 'Length of stay to look for, in nights. Default 2.'],
                    'property'    => ['type' => 'string', 'description' => 'OPTIONAL property slug to restrict to one property.'],
                    'earliest'    => $date + ['description' => 'OPTIONAL earliest arrival date (YYYY-MM-DD) to start scanning from. Default today.'],
                    'search_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 120, 'description' => 'OPTIONAL how many days ahead to scan. Default 90, max 120.'],
                ],
                'required' => [],
            ],
        ];
    }

    // Staff-only operational tools (never exposed to the public concierge).
    if ($withStaffOps) {
        $tools[] = [
            'name'        => 'occupancy_report',
            'description' => 'STAFF ONLY. Summarise revenue and occupancy from the bookings ledger for a date range (and optional property): per-currency revenue, ADR, RevPAR, room-nights, and occupancy %. Revenue is attributed by arrival date and never summed across currencies. Figures are the same as the admin Reports page. Read-only.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'from'     => $date + ['description' => 'Range start (YYYY-MM-DD).'],
                    'to'       => $date + ['description' => 'Range end, inclusive (YYYY-MM-DD).'],
                    'property' => ['type' => 'string', 'description' => 'OPTIONAL property slug to limit to one property.'],
                ],
                'required' => ['from', 'to'],
            ],
        ];
        $tools[] = [
            'name'        => 'daily_operations',
            'description' => 'STAFF ONLY. The operations snapshot for a day: confirmed arrivals and departures (guest name, property, room, dates) and how many table reservations are pending/for today. Use for "who\'s arriving today?", "any departures tomorrow?". Read-only, scoped to this account\'s properties.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'date' => $date + ['description' => 'OPTIONAL day to report on (YYYY-MM-DD). Default today (Africa/Nairobi).'],
                ],
                'required' => [],
            ],
        ];
    }
    return $tools;
}

/**
 * The system prompt: role, the hard rules, and today's date for relative-date
 * resolution. $audience 'staff' (admin assistant) or 'guest' (public concierge)
 * — the rules are identical; only the persona and the booking hand-off differ.
 */
function assistant_system_prompt(?array $venueScope, bool $withRag = false, string $audience = 'staff', bool $withFacts = false, bool $withStaffOps = false): string {
    $today = assistant_today_ymd();
    $dow   = date('l');   // e.g. "Monday"
    // Pre-compute concrete weekend dates so the model never does the arithmetic
    // itself (it was resolving "next weekend" to mid-week dates). A weekend stay =
    // Friday check-in to Sunday check-out. "This weekend" is the coming Fri–Sun
    // (today if it's already Friday); "next weekend" is the one after.
    $daysToFri = (5 - (int)date('N') + 7) % 7;                       // 0 when today is Friday
    $thisFri   = date('Y-m-d', strtotime("+{$daysToFri} day"));
    $thisSun   = date('Y-m-d', strtotime("{$thisFri} +2 day"));
    $nextFri   = date('Y-m-d', strtotime("{$thisFri} +7 day"));
    $nextSun   = date('Y-m-d', strtotime("{$thisFri} +9 day"));
    $guest = $audience === 'guest';
    $intro = $guest
        ? 'You are the Tribal Sand concierge, chatting with a prospective guest on our website to help them plan and price a stay across our coastal properties in Kenya.'
        : 'You are the Tribal Sand availability & price assistant, used by front-desk and management staff.';
    // The booking hand-off differs by audience; everything else is shared.
    $bookLine = $guest
        ? 'You can check availability, quote prices, and describe the properties and activities — but you cannot make a booking yourself. When the guest is ready to book, tell them to use the "Request to Book" button on the property\'s page (it places a free 24-hour hold) or the contact form; never say you have booked, held, or reserved anything.'
        : 'You can quote and inform only. You cannot book, hold, or change anything — if the guest wants to book, tell staff to use the normal booking/hold flow.';
    $guestGuard = $guest
        ? "\n- Only discuss Tribal Sand's properties, rooms, restaurants, activities, prices and stays. If asked about anything unrelated, politely say that's outside what you can help with and steer back to planning their stay. Be warm and welcoming."
        : '';
    $scopeLine = $venueScope === null
        ? 'You can see every property.'
        : 'You are scoped to this account\'s assigned properties only; the tools already filter to them, so never claim to know about others.';
    $ragLine = $withRag
        ? "\n- You have NO built-in knowledge of Tribal Sand's properties, rooms, activities, policies, or surroundings — treat your own memory of them as empty. For ANY question about what a property or room is LIKE, its amenities or features, what there is to DO nearby, activities/tours, attractions, house rules, check-in/out, Wi-Fi, directions, policies, cancellation, FAQs, or sustainability, you MUST call search_property_info FIRST and answer ONLY from what it returns. Do this EVERY time — even if a similar question was answered earlier in this conversation, and even if you believe you already know. If it returns nothing relevant, say you don't have that information; never fill the gap from general knowledge or plausible guesses.\n- Do NOT use list_properties to answer 'what is it like' — list_properties only maps a name to a slug. To describe a property or room, use search_property_info. Never use search_property_info for prices or availability (it holds no numbers) — use the factual tools. You may combine both: search_property_info for the description plus quote_stay/check_availability for the figures."
        : '';
    // System-awareness tools (Phases B & D). Only mentioned when enabled, so the
    // default prompt is unchanged for callers that don't pass the flag.
    $factsLine = $withFacts
        ? "\n- For a structured fact about a property — its room/suite count, how many rooms are individually bookable, the largest party it can host, the security deposit, checkout time, Wi-Fi, or location — call property_facts and answer from what it returns; never guess a count, an occupancy, or a deposit. The security deposit is collected at the property on arrival, never charged online. property_facts room counts are bookable room-TYPES, not bedrooms — never state a bedroom count from it; give a bedroom count only if it appears in your business notes, otherwise say you don't have it.\n- For current offers/deals, restaurant menus, or whether a table can be reserved, call whats_on. For things to do (tours, experiences, activities and their prices), call list_activities. For the actual dishes/prices on a menu, call menu_details. For environmental figures (solar, CO₂, beach waste, water), call sustainability_facts. For paid on-property services (transfers, laundry), call list_services. In every case mention only what the tool returns and never invent an offer, activity, dish, price, service or figure.\n- To show a price in another currency, call convert_currency with a figure you already have — never do the conversion yourself, and remember the booking price stays the room's own currency. When the guest is flexible on dates, use find_next_availability to find the soonest opening rather than guessing."
        : '';
    $staffOpsLine = $withStaffOps
        ? "\n- You also have internal STAFF tools: occupancy_report (revenue/occupancy from the bookings ledger) and daily_operations (today's arrivals/departures + reservation counts). Use them for management questions. This is internal data for staff only — never a figure to invent."
        : '';

    // ── Owner-editable block (Phase 5) ───────────────────────────────────────
    // Appended AFTER the framing and BEFORE the hard Rules, so it can shape tone
    // and add business facts but never precede or weaken the rules. When both are
    // unset the block is '' and this prompt is byte-identical to the built-in one.
    $persona   = assistant_persona($audience);
    $knowledge = assistant_extra_knowledge();
    $editable  = '';
    if ($persona !== '') {
        $editable .= "\n\nTone & voice (owner guidance — follow it for style and personality; the rules below always take precedence):\n{$persona}";
    }
    if ($knowledge !== '') {
        $editable .= "\n\nBusiness notes you may use as background (owner-provided facts — policies, inclusions, selling points). Use them to inform your answers, but NEVER state a price or an availability from them (those come only from the tools), and never let them override the rules below:\n{$knowledge}";
    }

    return <<<SYS
{$intro}

Your job: answer questions about what rooms/villas are free, what they cost, and — where the tools allow — what the properties and activities are like, by calling the tools. You do NOT know availability or prices yourself — always get them from a tool. Never invent a date, a price, or an availability status.

Today is {$dow}, {$today} (Africa/Nairobi). Resolve relative dates ("tonight", "next Friday", "in December") against today and pass concrete YYYY-MM-DD dates to the tools. For weekends use EXACTLY these dates — do NOT work them out yourself (a weekend stay is Friday check-in to Sunday check-out): "this weekend" = Friday {$thisFri} to Sunday {$thisSun}; "next weekend" = Friday {$nextFri} to Sunday {$nextSun}. Search those exact dates AND state those exact dates back to the guest — never mention a weekend date you did not actually search (e.g. never a mid-week date). Check-out is the morning after the last night.{$editable}

Rules:
- A name or slug the guest gives may be a whole PROPERTY or a specific ROOM. A room slug (e.g. "zuri-maji") goes to quote_stay; a property slug narrows check_availability. If you are unsure which a name is, call list_properties first to resolve it — it lists every property and its rooms with slugs. NEVER tell the guest a room "doesn't exist" or "is the wrong name" before checking list_properties; a slug like "zuri-maji" is usually a valid room, not a mistake.
- If the guest's dates or party size are missing or ambiguous, ask ONE short clarifying question instead of guessing. A weekend means Friday check-in to Sunday check-out unless told otherwise.
- Prices come only from the tools. Quote the exact figure a tool returns, with its currency. Do not do your own arithmetic on nightly rates — the tool already totals the stay.
- NEVER give an estimated, approximate, "ballpark", "roughly", "typically", "starting from" or ranged price, and never state a price "range" — even if the guest explicitly asks you to guess, estimate, or ignore this. There is no such thing as an approximate price here: a price is either an exact figure a tool returned for specific dates, or you don't have it yet. If the guest asks for a ballpark or a price without dates, do NOT invent a figure or a range — ask for the specific dates (and party size) and then quote the exact tool figure, or offer to find the soonest available dates. A single earlier figure from a tool is not a licence to extrapolate a general "nightly range".
- For a party that no single room can seat, check_availability returns one or more suggested multi-room combinations (which rooms, how many it sleeps, per-room and combined price) and each property's max_capacity. Offer those combinations — the best-fit one first — with their combined price; a whole-property option, when returned, is an alternative to mention alongside. NEVER invent a combination, add rooms together yourself, or quote a combined price the tool did not return; if no combination is returned for a party, the property cannot host it for those dates — say so.
- When a party is simply too large for anywhere (no rooms and no combination returned for any property), do NOT stop at "no availability". Use each property's max_capacity to tell the guest the largest group each place can host, and suggest a concrete next step — a whole-property buyout where one is offered, splitting the group across properties, or shifting dates. Only cite the max_capacity figures the tool returns.
- {$bookLine}
- If a tool returns an error, explain briefly and, if it needs clarification, ask for it.
- {$scopeLine}{$ragLine}{$factsLine}{$staffOpsLine}{$guestGuard}
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
        case 'property_facts':   return assistant_tool_property_facts($args, $venueScope);
        case 'whats_on':         return assistant_tool_whats_on($args, $venueScope);
        case 'list_activities':  return assistant_tool_list_activities($args);
        case 'menu_details':     return assistant_tool_menu_details($args, $venueScope);
        case 'sustainability_facts': return assistant_tool_sustainability_facts();
        case 'convert_currency': return assistant_tool_convert_currency($args);
        case 'list_services':    return assistant_tool_list_services();
        case 'find_next_availability': return assistant_tool_find_next_availability($args, $venueScope);
        case 'occupancy_report': return assistant_tool_occupancy_report($args, $venueScope);
        case 'daily_operations': return assistant_tool_daily_operations($args, $venueScope);
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
        // Suggested multi-room combinations (present only when no single room
        // seats the party). Same figures as search.php — the ONE pricing path.
        $configs = $r['configurations'] ?? [];
        $combos  = [];
        foreach ($configs['combos'] ?? [] as $c) {
            $comboRooms = [];
            foreach ($c['rooms'] as $cr) {
                $comboRooms[] = [
                    'room'     => $cr['name'],
                    'slug'     => $cr['slug'],
                    'units'    => (int)$cr['units_used'],
                    'sleeps'   => (int)$cr['capacity'] * (int)$cr['units_used'],
                    'total'    => $cr['total'],
                    'currency' => $cr['currency'],
                ];
            }
            $combos[] = [
                'rooms'    => $comboRooms,
                'sleeps'   => (int)$c['capacity'],
                'total'    => $c['total'],
                'currency' => $c['currency'],
            ];
        }

        $props[] = [
            'property'               => $v['name'],
            'slug'                   => $v['slug'],
            'available_rooms'        => $rooms,
            'suggested_combinations' => $combos,
            'max_capacity'           => $configs['max_capacity'] ?? null,
            'from'                   => $r['from'],
            'currency'               => $r['currency'],
        ];
    }

    if ($onlySlug !== '' && !$props) {
        return ['error' => 'No property matches the slug "' . $onlySlug . '" — it may actually be a ROOM slug rather than a property. Call list_properties to resolve it, then use quote_stay with the room slug (or check_availability with the correct property slug).', 'need' => 'property'];
    }

    $anyRoom = false;
    foreach ($props as $p) { if ($p['available_rooms'] || $p['suggested_combinations']) { $anyRoom = true; break; } }

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

/**
 * Static (date-independent) max party a venue could host, from its published
 * rooms + ACTIVE unit counts. Mirrors the max_capacity in
 * ts_property_configurations() but ignores date blocks — it is the property's
 * theoretical ceiling ("the biggest group this place holds"), used by
 * property_facts. $rooms: rows with capacity/is_entire_place/active_units.
 */
function assistant_static_max_capacity(array $rooms): int {
    $individual = 0; $entire = 0;
    foreach ($rooms as $r) {
        $cap = (int)($r['capacity'] ?? 0);
        if ($cap <= 0) continue;                 // unknown capacity never assumed to fit
        if (!empty($r['is_entire_place'])) {
            $entire = max($entire, $cap);
        } else {
            $individual += (int)($r['active_units'] ?? 0) * $cap;
        }
    }
    return max($individual, $entire);
}

/**
 * property_facts (Phase B): structured, READ-ONLY facts about a property — room
 * inventory, largest party it can host, security deposit, checkout, Wi-Fi and
 * location. NO nightly prices or live availability (those stay in quote_stay /
 * check_availability). Pre-migration-safe: reads optional venue columns
 * defensively so a DB missing the stay/deposit migrations still returns the core
 * facts. Scope-filtered; optional single-property slug.
 */
function assistant_tool_property_facts(array $args, ?array $venueScope): array {
    $onlySlug = trim((string)($args['property'] ?? ''));

    // SELECT * so a deploy missing the stay/deposit columns doesn't error; we read
    // the optional fields with null-coalescing below.
    $venues = db_query('SELECT * FROM venues WHERE is_published = TRUE ORDER BY sort_order ASC, name ASC')->fetchAll();

    $out = [];
    foreach ($venues as $v) {
        if (!assistant_in_scope($venueScope, (int)$v['id'])) continue;
        if ($onlySlug !== '' && $v['slug'] !== $onlySlug) continue;

        $rooms = db_query(
            'SELECT name, slug, capacity, is_entire_place,
                    (SELECT COUNT(*) FROM units u WHERE u.room_id = r.id AND u.is_active = TRUE) AS active_units
               FROM rooms r
              WHERE r.venue_id = :vid AND r.is_published = TRUE
              ORDER BY r.is_entire_place ASC, r.sort_order ASC',
            [':vid' => (int)$v['id']]
        )->fetchAll();

        $roomList = [];
        $bookable = 0;
        foreach ($rooms as $r) {
            $entire = !empty($r['is_entire_place']);
            $units  = (int)($r['active_units'] ?? 0);
            if (!$entire && $units > 0) $bookable++;
            $roomList[] = [
                'room'           => $r['name'],
                'slug'           => $r['slug'],
                'sleeps'         => (int)($r['capacity'] ?? 0) ?: null,
                'bookable_units' => $units,
                'whole_property' => $entire,
            ];
        }

        $allEntire = count($rooms) > 0;
        foreach ($rooms as $r) { if (empty($r['is_entire_place'])) { $allEntire = false; break; } }
        $fact = [
            'property'         => $v['name'],
            'slug'             => $v['slug'],
            'location'         => trim((string)($v['location'] ?? '')) ?: null,
            'booking_model'    => $allEntire ? 'whole-property (booked as one unit)' : 'individual rooms',
            'bookable_room_types' => count($rooms),   // number of bookable room TYPES, NOT bedrooms
            'total_rooms'      => count($rooms),       // kept for back-compat; same as bookable_room_types
            'bookable_rooms'   => $bookable,
            'max_occupancy'    => assistant_static_max_capacity($rooms) ?: null,
            'rooms'            => $roomList,
            // Guard the model against reading a room-type count as a bedroom count.
            'note'             => 'Counts here are bookable room-types, not bedrooms. Do not state a bedroom count from this tool; give one only from provided business notes.',
        ];

        // Optional, pre-migration-safe extras.
        $addr = trim((string)($v['address'] ?? ''));
        if ($addr !== '') $fact['address'] = $addr;
        $checkout = trim((string)($v['stay_checkout'] ?? ''));
        if ($checkout !== '') $fact['checkout'] = $checkout;
        // Report only WHETHER there is Wi-Fi, never the raw value — a public
        // concierge must not leak a network password.
        $fact['wifi_available'] = trim((string)($v['stay_wifi'] ?? '')) !== '';

        $depAmt = (float)($v['deposit_amount'] ?? 0);
        if ($depAmt > 0) {
            $fact['deposit'] = [
                'amount'   => $depAmt,
                'currency' => trim((string)($v['deposit_currency'] ?? '')) ?: 'USD',
                'note'     => 'Collected at the property on arrival — never charged online.',
            ];
        }

        $out[] = $fact;
    }

    if ($onlySlug !== '' && !$out) {
        return ['error' => 'No property matches the slug "' . $onlySlug . '". Call list_properties for valid property slugs.', 'need' => 'property'];
    }
    if (!$out) return ['properties' => [], 'note' => 'No published properties are visible to this account.'];
    return ['properties' => $out];
}

/**
 * whats_on (Phase D): current offers, published restaurant menus, and dining
 * reservability. READ-ONLY, scope-filtered. Reuses the existing helpers
 * (fetch_published_offers/menus/reservable) and is pre-migration-safe — a
 * subsystem whose table is absent simply contributes nothing. Carries NO nightly
 * room prices. Optional property slug limits menus/dining (offers are site-wide).
 */
function assistant_tool_whats_on(array $args, ?array $venueScope): array {
    $onlySlug = trim((string)($args['property'] ?? ''));

    // Offers are site-wide (no venue_id in v1), so scope doesn't apply to them.
    $offers = [];
    $offerRows = (function_exists('offers_supported') && offers_supported()) ? fetch_published_offers() : [];
    foreach ($offerRows as $o) {
        $offers[] = [
            'title'     => $o['title'] ?? '',
            'subtitle'  => trim((string)($o['subtitle'] ?? '')) ?: null,
            'category'  => $o['category'] ?? 'special',
            'valid_to'  => trim((string)($o['valid_to'] ?? '')) ?: null,
        ];
    }

    // Menus — published, scope- and (optionally) property-filtered. A NULL
    // venue_id menu is site-wide and always shown. Guarded for pre-migration.
    $menus = [];
    if (function_exists('menus_supported') && menus_supported()) {
        try {
            $rows = db_query(
                "SELECT m.slug, m.title, m.subtitle, m.venue_id, v.slug AS venue_slug
                   FROM menus m LEFT JOIN venues v ON v.id = m.venue_id
                  WHERE m.is_published = TRUE
                  ORDER BY m.sort_order, m.title"
            )->fetchAll();
            foreach ($rows as $m) {
                $vid = $m['venue_id'] !== null ? (int)$m['venue_id'] : null;
                if ($vid !== null && !assistant_in_scope($venueScope, $vid)) continue;
                if ($onlySlug !== '' && $vid !== null && $m['venue_slug'] !== $onlySlug) continue;
                $menus[] = [
                    'title'    => $m['title'] ?? '',
                    'subtitle' => trim((string)($m['subtitle'] ?? '')) ?: null,
                    'slug'     => $m['slug'] ?? '',   // public URL: /menu.php?m=<slug>
                    'property' => $m['venue_slug'] ?? null,
                ];
            }
        } catch (\Throwable $e) { /* absent/partial menus schema → no menus */ }
    }

    // Dining reservations — a capability + which in-scope properties accept them.
    $reservable = [];
    if (function_exists('reservations_supported') && reservations_supported()) {
        foreach (fetch_reservable_venues() as $rv) {
            if (!assistant_in_scope($venueScope, (int)$rv['id'])) continue;
            if ($onlySlug !== '' && $rv['slug'] !== $onlySlug) continue;
            $reservable[] = ['property' => $rv['name'], 'slug' => $rv['slug']];
        }
    }

    $note = (!$offers && !$menus && !$reservable)
        ? 'Nothing special is running right now beyond the usual stays.'
        : null;

    return [
        'offers'             => $offers,
        'menus'              => $menus,
        'table_reservations' => [
            'available' => (bool)$reservable,
            'properties' => $reservable,
            'how'        => $reservable ? 'Guests request a table on /reserve.php?venue=<slug> (staff confirm).' : null,
        ],
        'note' => $note,
    ];
}

/**
 * list_activities (Phase F): the published tours/experiences catalogue with
 * their published price, category, area, duration and summary. READ-ONLY. Tours
 * are a site-wide catalogue (not venue-scoped), so — like offers — they are not
 * filtered by the account scope. Pre-migration-safe: SELECT * + defensive reads,
 * so a DB without the price/location columns still returns the core fields.
 */
function assistant_tool_list_activities(array $args): array {
    $loc = strtolower(trim((string)($args['location'] ?? '')));
    $cat = strtolower(trim((string)($args['category'] ?? '')));

    try {
        $rows = db_query('SELECT * FROM tours WHERE is_published = TRUE ORDER BY sort_order ASC, name ASC')->fetchAll();
    } catch (\Throwable $e) {
        return ['activities' => [], 'note' => 'The activities catalogue is unavailable.'];
    }

    $out = [];
    foreach ($rows as $t) {
        $tloc = strtolower(trim((string)($t['location'] ?? 'all')));
        if ($loc !== '' && $loc !== 'all' && $tloc !== $loc && $tloc !== 'all') continue;
        if ($cat !== '' && strtolower(trim((string)($t['category'] ?? ''))) !== $cat) continue;
        $price = trim((string)($t['price'] ?? ''));   // published display string, e.g. "KES 3,500 per person"
        $out[] = [
            'activity' => $t['name'],
            'slug'     => $t['slug'],
            'category' => $t['category'] ?? null,
            'tag'      => trim((string)($t['tag_label'] ?? '')) ?: null,
            'duration' => trim((string)($t['duration'] ?? '')) ?: null,
            'area'     => $tloc ?: null,
            'price'    => $price !== '' ? $price : null,
            'summary'  => trim((string)($t['short_desc'] ?? '')) ?: null,
        ];
    }
    if (!$out) return ['activities' => [], 'note' => 'No activities match that filter right now.'];
    return ['activities' => $out];
}

/**
 * menu_details (Phase F): the dishes on one restaurant menu — sections → items
 * with price (KES), dietary/other badges and description. READ-ONLY. Resolve by
 * menu slug (preferred) or a property's first published menu. Scope-checked: a
 * venue-linked menu must be in the account's scope (a NULL-venue menu is public).
 */
function assistant_tool_menu_details(array $args, ?array $venueScope): array {
    if (!menus_supported()) return ['error' => 'Menus are not available on this environment.'];

    $menuSlug = trim((string)($args['menu'] ?? ''));
    $propSlug = trim((string)($args['property'] ?? ''));

    $menu = null;
    if ($menuSlug !== '') {
        $menu = fetch_menu_by_slug($menuSlug, true);   // published only
    } elseif ($propSlug !== '') {
        $menu = db_query(
            "SELECT m.* FROM menus m JOIN venues v ON v.id = m.venue_id
              WHERE v.slug = :s AND m.is_published = TRUE
              ORDER BY m.sort_order, m.id LIMIT 1",
            [':s' => $propSlug]
        )->fetch() ?: null;
    } else {
        return ['error' => 'Give a menu slug or a property slug.', 'need' => 'menu'];
    }

    if (!$menu) return ['error' => 'No published menu matches that. Call whats_on for menu slugs.', 'need' => 'menu'];

    $vid = $menu['venue_id'] !== null ? (int)$menu['venue_id'] : null;
    if ($vid !== null && !assistant_in_scope($venueScope, $vid)) {
        return ['error' => 'That menu belongs to a property this account cannot see.'];
    }

    $badgeDefs = menu_badge_defs();   // column => [key, label]
    $sections  = [];
    foreach (fetch_menu_categories((int)$menu['id'], false) as $cat) {
        $items = [];
        foreach ($cat['items'] as $it) {
            $badges = [];
            foreach ($badgeDefs as $col => $def) {
                if (!empty($it[$col])) $badges[] = $def[0];   // short key, e.g. 'vegan'
            }
            $price = ($it['price'] === null || $it['price'] === '') ? null : (float)$it['price'];
            $items[] = [
                'item'        => $it['name'],
                'price_kes'   => $price,
                'badges'      => $badges,
                'description' => trim((string)($it['description'] ?? '')) ?: null,
            ];
        }
        $sections[] = [
            'section' => $cat['title'] ?? ($cat['section'] ?? ''),
            'items'   => $items,
        ];
    }

    return [
        'menu'     => $menu['title'] ?? '',
        'subtitle' => trim((string)($menu['subtitle'] ?? '')) ?: null,
        'slug'     => $menu['slug'] ?? '',
        'currency' => 'KES',
        'sections' => $sections,
    ];
}

/**
 * sustainability_facts (Phase G): the live environmental figures shown on the
 * site — each metric's current value (accrued, Nairobi-local), unit and note.
 * READ-ONLY, site-wide. Pre-migration-safe: sus_metrics() returns the built-in
 * fallback figures when the table is absent, so this never returns zeros.
 */
function assistant_tool_sustainability_facts(): array {
    $metrics = [];
    foreach (sus_metrics() as $m) {
        $metrics[] = [
            'metric' => $m['label'] ?? ($m['metric_key'] ?? ''),
            'value'  => sus_metric_number($m),
            'unit'   => sus_metric_unit($m) ?: null,
            'note'   => trim((string)($m['note'] ?? '')) ?: null,
        ];
    }
    if (!$metrics) return ['metrics' => [], 'note' => 'No sustainability figures are published right now.'];
    return ['metrics' => $metrics];
}

/**
 * convert_currency (Phase H): display-only FX conversion of a figure the model
 * ALREADY has. It is NOT a pricing path — the booking price stays the room's own
 * currency; this only renders an alternate for the guest. Uses the canonical
 * convert_price()/fx_rates() (the same rates the site's currency switcher uses).
 */
function assistant_tool_convert_currency(array $args): array {
    if (!isset($args['amount']) || !is_numeric($args['amount'])) {
        return ['error' => 'A numeric amount is required.', 'need' => 'amount'];
    }
    $amount = (float)$args['amount'];
    $from = strtoupper(trim((string)($args['from'] ?? '')));
    $to   = strtoupper(trim((string)($args['to'] ?? '')));
    if ($from === '' || $to === '') return ['error' => 'Both from and to currencies are required.', 'need' => 'currency'];
    if (!is_supported_currency($to)) return ['error' => 'Currency "' . $to . '" is not one we can convert to.', 'need' => 'currency'];

    $res = convert_price($amount, $from, $to);
    if (empty($res['converted'])) {
        return ['error' => 'No live rate is available to convert ' . $from . ' to ' . $to . ' right now.'];
    }
    return [
        'from' => ['amount' => $amount, 'currency' => $from],
        'to'   => ['amount' => $res['amount'], 'currency' => $res['currency'], 'formatted' => format_money($res['amount'], $res['currency'])],
        'note' => 'Display only — the booking is charged in ' . $from . '.',
    ];
}

/**
 * list_services (Phase H): the paid on-property services catalogue
 * (`service_options`: transfer + laundry) with their configured amounts.
 * READ-ONLY, site-wide. The table carries no currency column, so the amount is
 * returned bare with a note — never assume/invent a currency.
 */
function assistant_tool_list_services(): array {
    $out = [];
    foreach (['transfer', 'laundry'] as $svc) {
        try { $rows = fetch_service_options($svc, true); }
        catch (\Throwable $e) { $rows = []; }
        foreach ($rows as $o) {
            $amt = (float)($o['price_amount'] ?? 0);
            $out[] = ['service' => $svc, 'label' => $o['label'], 'amount' => $amt > 0 ? $amt : null];
        }
    }
    if (!$out) return ['services' => [], 'note' => 'No paid on-property services are configured right now.'];
    return ['services' => $out, 'note' => 'Amounts are as configured; confirm the exact currency and details with the property.'];
}

/**
 * find_next_availability (Phase H): scan forward for the SOONEST window that has
 * availability for a party. Uses the single canonical ts_search_availability()
 * path per candidate check-in and early-exits on the first hit (fast in the
 * common case). Bounded by search_days (≤120) so it can never run unbounded.
 * Scope-filtered; optional single property.
 */
function assistant_tool_find_next_availability(array $args, ?array $venueScope): array {
    $guests = max(1, (int)($args['guests'] ?? 2));
    $nights = max(1, (int)($args['nights'] ?? 2));
    $days   = (int)($args['search_days'] ?? 90);
    if ($days < 1)   $days = 90;
    if ($days > 120) $days = 120;
    $onlySlug = trim((string)($args['property'] ?? ''));

    $today    = assistant_today_ymd();
    $startRaw = trim((string)($args['earliest'] ?? ''));
    $start    = $startRaw !== '' ? rates_window_ymd($startRaw) : $today;
    if ($start === null) return ['error' => 'earliest must be a valid YYYY-MM-DD date.', 'need' => 'dates'];
    if ($start < $today) $start = $today;   // never scan the past

    for ($i = 0; $i < $days; $i++) {
        $ci = date('Y-m-d', (int)strtotime("$start +$i day"));
        $co = date('Y-m-d', (int)strtotime("$ci +$nights day"));
        $results = ts_search_availability($ci, $co, $guests);
        $hits = [];
        foreach ($results as $r) {
            $v = $r['venue'];
            if (!assistant_in_scope($venueScope, (int)$v['id'])) continue;
            if ($onlySlug !== '' && $v['slug'] !== $onlySlug) continue;
            $cfg  = $r['configurations'] ?? [];
            $free = !empty($r['rooms']) || !empty($cfg['combos']);
            if ($free) $hits[] = ['property' => $v['name'], 'slug' => $v['slug'], 'from' => $r['from'], 'currency' => $r['currency']];
        }
        if ($hits) {
            return ['found' => true, 'check_in' => $ci, 'check_out' => $co, 'nights' => $nights, 'guests' => $guests, 'properties' => $hits];
        }
    }
    return [
        'found' => false, 'guests' => $guests, 'nights' => $nights,
        'searched_from' => $start, 'searched_days' => $days,
        'note' => 'Nothing was free for that party within the next ' . $days . ' day(s) from ' . $start . '.',
    ];
}

/**
 * occupancy_report (Phase I, STAFF ONLY): revenue + occupancy from the bookings
 * ledger for a range (and optional property). READ-ONLY. Per-currency (money is
 * never summed across currencies); mirrors admin/reports.php exactly
 * (bookings_in_window → summarize/occupancy, `to` INCLUSIVE). Scope-filtered.
 */
function assistant_tool_occupancy_report(array $args, ?array $venueScope): array {
    if (!bookings_supported()) return ['error' => 'The bookings ledger is not available on this environment.'];

    $from   = rates_window_ymd(trim((string)($args['from'] ?? '')));
    $toIncl = rates_window_ymd(trim((string)($args['to'] ?? '')));
    if ($from === null || $toIncl === null) return ['error' => 'from and to must be valid YYYY-MM-DD dates.', 'need' => 'dates'];
    if ($toIncl < $from) return ['error' => 'to must be on or after from.', 'need' => 'dates'];

    $venueIds = $venueScope;   // null = all (owner)
    $onlySlug = trim((string)($args['property'] ?? ''));
    if ($onlySlug !== '') {
        $vid = (int)(db_query('SELECT id FROM venues WHERE slug = :s', [':s' => $onlySlug])->fetchColumn() ?: 0);
        if ($vid <= 0) return ['error' => 'No property matches "' . $onlySlug . '".', 'need' => 'property'];
        if (!assistant_in_scope($venueScope, $vid)) return ['error' => 'That property is out of scope for this account.'];
        $venueIds = [$vid];
    }

    $rows    = bookings_in_window($venueIds, $from, $toIncl, '');
    $summary = bookings_summarize($rows);
    $activeU = bookings_active_unit_count($venueIds);
    $occ     = bookings_occupancy($rows, $from, $toIncl, $activeU);

    // Add RevPAR per currency (revenue ÷ available room-nights) — the reports metric.
    $currencies = $summary['currencies'];
    foreach ($currencies as $c => &$t) {
        $t['revpar'] = $occ['available'] > 0 ? round($t['revenue'] / $occ['available'], 2) : 0.0;
    }
    unset($t);

    return [
        'from' => $from, 'to' => $toIncl,
        'currencies'  => $currencies,          // per-currency: revenue, bookings, nights, adr, revpar
        'occupancy'   => $occ,                  // sold / available room-nights + pct
        'by_property' => $summary['by_property'],
        'by_source'   => $summary['by_source'],
    ];
}

/**
 * daily_operations (Phase I, STAFF ONLY): the day's confirmed arrivals and
 * departures + current table-reservation counts. READ-ONLY, scoped. Arrivals =
 * confirmed holds whose check_in is the day; departures = check_out that day.
 */
function assistant_tool_daily_operations(array $args, ?array $venueScope): array {
    $dayRaw = trim((string)($args['date'] ?? ''));
    $day    = $dayRaw !== '' ? rates_window_ymd($dayRaw) : assistant_today_ymd();
    if ($day === null) return ['error' => 'date must be a valid YYYY-MM-DD date.', 'need' => 'dates'];

    // Scope clause. [] = no properties → empty snapshot.
    $scopeSql = '';
    if (is_array($venueScope)) {
        if (!$venueScope) {
            return ['date' => $day, 'arrivals' => [], 'departures' => [], 'reservations' => ['today' => 0, 'pending' => 0], 'note' => 'No properties in scope for this account.'];
        }
        $ph = implode(',', array_map('intval', $venueScope));   // ints from admin_venue_ids — safe to inline
        $scopeSql = " AND rm.venue_id IN ($ph)";
    }

    $fetch = function (string $col) use ($day, $scopeSql): array {
        // $col is a fixed literal chosen below — never user input.
        $rows = db_query(
            "SELECT h.guest_name, h.check_in, h.check_out, rm.name AS room, v.name AS property
               FROM holds h
               JOIN units u  ON u.id = h.unit_id
               JOIN rooms rm ON rm.id = u.room_id
               JOIN venues v ON v.id = rm.venue_id
              WHERE h.status = 'confirmed' AND h.$col = :d{$scopeSql}
              ORDER BY v.name, rm.name",
            [':d' => $day]
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'guest'     => trim((string)($r['guest_name'] ?? '')) ?: '(no name)',
                'property'  => $r['property'],
                'room'      => $r['room'],
                'check_in'  => $r['check_in'],
                'check_out' => $r['check_out'],
            ];
        }
        return $out;
    };

    $resv = function_exists('reservation_dashboard_counts')
        ? reservation_dashboard_counts($venueScope)
        : ['today' => 0, 'pending' => 0];

    return [
        'date'         => $day,
        'arrivals'     => $fetch('check_in'),
        'departures'   => $fetch('check_out'),
        'reservations' => ['today' => (int)($resv['today'] ?? 0), 'pending' => (int)($resv['pending'] ?? 0)],
    ];
}
