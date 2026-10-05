<?php
/**
 * The admin Dashboard — every account's landing page (admin/dashboard.php).
 *
 * One page, shaped by who is signed in: it says who you are, what you can do,
 * and shows the numbers your role acts on, each linking to the page where you
 * act on it. It adds no new figures of its own — every number comes from the
 * helper the linked page already uses (frontdesk_day(), count_unread_admin(),
 * reservation_dashboard_counts(), bookings_summarize(), pos_report_summarize()…),
 * so a tile and the page behind it can never disagree.
 *
 * Two layers:
 *   • PURE rules — dashboard_kind(), dashboard_profile(), dashboard_plan(),
 *     dashboard_greeting(): which sections, tiles and shortcuts a role gets.
 *     No DB, no session. Test: php tests/dashboard_logic.php.
 *   • Data — dashboard_tile_values(), dashboard_today(), dashboard_money()…
 *     Every lookup is scoped by admin_venue_ids() and wrapped so a missing
 *     table (pre-migration) or a failed query shows a dash, never a 500: this
 *     is the page everyone lands on after signing in.
 */
declare(strict_types=1);

// ───────────────────────────── pure rules ──────────────────────────────────

/** Which dashboard a signed-in account gets. */
function dashboard_kind(string $role, ?string $job): string {
    if ($role === 'owner' || $role === 'manager' || $role === 'reception') return $role;
    if ($job === 'security') return 'security';
    if ($job === 'storekeeper') return 'store';
    if (in_array($job, ['shop', 'spa', 'kite'], true)) return 'pos';
    if (in_array($job, ['housekeeping', 'laundry', 'maintenance', 'gardening', 'driver'], true)) return 'ops';
    return 'frontdesk';   // front-desk staff, and staff with no job type (same rule as admin_job())
}

/** "Good morning" by the Nairobi hour. */
function dashboard_greeting(int $hour): string {
    return $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
}

/**
 * Who you are and what you can do, in plain words.
 * @return array{label:string, badge:string, chips:string[], summary:string, can:string[], cannot:string}  chips = the can-list in two words each
 */
function dashboard_profile(string $kind, ?string $job, array $venueNames): array {
    $where = match (true) {
        $kind === 'owner'        => 'every property',
        count($venueNames) === 0 => 'no property yet — ask the owner to assign you one',
        count($venueNames) === 1 => $venueNames[0],
        default                  => implode(', ', array_slice($venueNames, 0, -1)) . ' and ' . end($venueNames),
    };
    $jobLabel = ['housekeeping' => 'Housekeeping', 'laundry' => 'Laundry', 'maintenance' => 'Maintenance', 'gardening' => 'Gardening',
                 'driver' => 'Driver', 'security' => 'Gate security', 'shop' => 'Shop', 'spa' => 'Salon & Spa', 'kite' => 'Kite school',
                 'storekeeper' => 'Storekeeper'][$job ?? ''] ?? 'Front desk';
    return match ($kind) {
        'owner' => ['label' => 'Owner', 'badge' => 'teal',
            'chips' => ['Confirm bookings', 'Set rates', 'Edit website', 'Manage staff', 'See accounts', 'Change settings'],
            'summary' => 'You have full access to every property and every setting.',
            'can' => ['Confirm or decline booking requests', 'Set rates and send quotes', 'Edit properties, rooms and the website',
                      'Manage staff, travel agents and logins', 'See reports, invoices and the accounts', 'Change settings, emails and the AI assistant'],
            'cannot' => ''],
        'manager' => ['label' => 'Manager', 'badge' => 'green',
            'chips' => ['Guest requests', 'Give out tasks', 'Attendance', 'Restaurant', 'Till and stock', 'Reports'],
            'summary' => "You run day-to-day operations at {$where}.",
            'can' => ['See arrivals, departures and guest requests', 'Give out tasks and set job timetables', 'Record attendance',
                      'Run the restaurant: menus, hours and table bookings', 'Manage the till, stock and inventory', 'See reports for your properties'],
            'cannot' => 'Prices, the website and staff logins are set by the owner.'],
        'reception' => ['label' => 'Reception', 'badge' => 'orange',
            'chips' => ['Confirm bookings', 'Answer enquiries', 'Send quotes', 'Guest requests', 'Table bookings', 'Gate'],
            'summary' => "You handle bookings and guests at {$where}.",
            'can' => ['Confirm or decline booking requests', 'Answer enquiries and send quotes', 'Use the calendar and sort out conflicts',
                      'Look after guest requests and messages', 'Take table reservations', 'Sign visitors in at the gate'],
            'cannot' => 'Prices, menus and reports are handled by the owner or a manager.'],
        'security' => ['label' => $jobLabel, 'badge' => 'blue',
            'chips' => ['Arrivals today', 'Sign visitors in', 'Your tasks', 'Team chat'],
            'summary' => "You look after the gate at {$where}.",
            'can' => ['See who is arriving and leaving today', 'Sign visitors in and out', 'See your own tasks and timetable', 'Chat with the team'],
            'cannot' => ''],
        'pos' => ['label' => $jobLabel, 'badge' => 'blue',
            'chips' => ['Open the till', 'Room charges', 'Your till PIN', 'Team chat'],
            'summary' => 'You sell at the till.',
            'can' => ['Open the till and take payments', 'Charge a sale to a guest\'s room', 'Set your own till PIN', 'Chat with the team'],
            'cannot' => ''],
        'store' => ['label' => $jobLabel, 'badge' => 'blue',
            'chips' => ['Stock list', 'Receive orders', 'Move stock', 'Stock counts', 'Team chat'],
            'summary' => "You look after the stock at {$where}.",
            'can' => ['See and update the stock list', 'Receive deliveries against orders', 'Move stock between stores and places',
                      'Count stock when a count is due', 'See your own tasks and timetable', 'Chat with the team'],
            'cannot' => 'Item values, deleting items and settling count differences are for the owner or a manager.'],
        'ops' => ['label' => $jobLabel, 'badge' => 'blue',
            'chips' => ['Tasks today', 'Your timetable', 'Stock counts', 'Team chat'],
            'summary' => "Your work at {$where}.",
            'can' => ['See today\'s tasks and mark them done', 'See your week on the timetable', 'Count stock when a count is due', 'Chat with the team'],
            'cannot' => ''],
        default => ['label' => $jobLabel, 'badge' => 'blue',
            'chips' => ['Arrivals', 'Guest requests', 'Guest messages', 'Team chat'],
            'summary' => "You look after guests at {$where}.",
            'can' => ['See arrivals and departures', 'Look after guest requests', 'Reply to guest messages', 'Chat with the team'],
            'cannot' => 'Bookings and prices are handled by reception and the owner.'],
    };
}

/**
 * What a dashboard shows, in order. Tile keys are looked up by
 * dashboard_tile_values(); a tile's page is one the role can actually open
 * (the same audiences admin/_layout.php uses for the sidebar).
 *
 * @param array $has optional modules: reservations, inventory, pos, tasks, visitors, ai
 * @return array{tiles:string[], sections:string[], shortcuts:array<int,array{0:string,1:string,2:string}>}  shortcut = [label, href, icon]
 */
function dashboard_plan(string $kind, array $has = []): array {
    $on = fn(string $k): bool => !empty($has[$k]);
    $plan = match ($kind) {
        'owner' => [
            'tiles'     => ['requests', 'enquiries', 'conflicts', 'guest_requests', 'messages', 'reservations', 'team_overdue', 'counts_review'],
            'sections'  => ['today', 'money', 'my_tasks', 'recent_enquiries'],
            'shortcuts' => [['New booking', '/admin/holds.php?new=1', 'plus'], ['Calendar', '/admin/gantt.php', 'calendar'], ['Quote builder', '/admin/quote-builder.php', 'file'],
                            ['Rates', '/admin/rates.php', 'coin'], ['Reports', '/admin/reports.php', 'chart']],
        ],
        'manager' => [
            'tiles'     => ['guest_requests', 'messages', 'reservations', 'team_overdue', 'counts_review'],
            'sections'  => ['today', 'money', 'my_tasks'],
            'shortcuts' => [['Front desk', '/admin/frontdesk.php', 'clipboard'], ['Tasks', '/admin/tasks.php', 'check-square'], ['Attendance', '/admin/attendance.php', 'clock'],
                            ['Reports', '/admin/reports.php', 'chart']],
        ],
        'reception' => [
            'tiles'     => ['requests', 'enquiries', 'conflicts', 'guest_requests', 'messages', 'reservations'],
            'sections'  => ['today', 'my_tasks', 'recent_enquiries'],
            'shortcuts' => [['New booking', '/admin/holds.php?new=1', 'plus'], ['Calendar', '/admin/gantt.php', 'calendar'], ['Quote builder', '/admin/quote-builder.php', 'file'],
                            ['Front desk', '/admin/frontdesk.php', 'clipboard']],
        ],
        'security' => [
            'tiles'     => ['visitors'],
            'sections'  => ['today', 'my_tasks'],
            'shortcuts' => [['Gate', '/admin/gate.php', 'shield'], ['Timetable', '/admin/timetable.php', 'calendar']],
        ],
        'pos' => [
            'tiles'     => [],
            'sections'  => ['till', 'my_tasks'],
            'shortcuts' => [['My till PIN', '/admin/pos-pins.php', 'lock'], ['Timetable', '/admin/timetable.php', 'calendar']],
        ],
        'store' => [
            'tiles'     => [],
            'sections'  => ['counts_due', 'my_tasks'],
            'shortcuts' => [['Inventory', '/admin/inventory.php', 'box'], ['Orders', '/admin/inventory-orders.php', 'file'],
                            ['Stock count', '/admin/inventory-count.php', 'check-square'], ['Timetable', '/admin/timetable.php', 'calendar']],
        ],
        'ops' => [
            'tiles'     => [],
            'sections'  => ['my_tasks', 'counts_due'],
            'shortcuts' => [['My work', '/admin/mywork.php', 'check-square'], ['Timetable', '/admin/timetable.php', 'calendar']],
        ],
        default => [
            'tiles'     => ['guest_requests', 'messages'],
            'sections'  => ['today', 'my_tasks'],
            'shortcuts' => [['Front desk', '/admin/frontdesk.php', 'clipboard'], ['Guest requests', '/admin/concierge-desk.php', 'bell'], ['Timetable', '/admin/timetable.php', 'calendar']],
        ],
    };
    $plan['tiles'][] = 'team_chat';   // every account has the team chat

    // Drop what an optional module would show when that module is not installed.
    $needs = ['reservations' => 'reservations', 'counts_review' => 'inventory', 'team_overdue' => 'tasks', 'visitors' => 'visitors'];
    $plan['tiles'] = array_values(array_filter($plan['tiles'], fn($t) => !isset($needs[$t]) || $on($needs[$t])));
    $secNeeds = ['counts_due' => 'inventory', 'till' => 'pos', 'my_tasks' => 'tasks'];
    $plan['sections'] = array_values(array_filter($plan['sections'], fn($s) => !isset($secNeeds[$s]) || $on($secNeeds[$s])));
    if ($on('ai') && in_array($kind, ['owner', 'manager', 'reception', 'frontdesk'], true)) $plan['shortcuts'][] = ['AI assistant', '/admin/assistant.php', 'sparkle'];
    return $plan;
}

/**
 * Every tile's wording and destination. `tone` colours the number when it is
 * above zero: warn = needs you, info = worth a look.
 * @return array<string,array{label:string, sub:string, href:string, tone:string}>
 */
function dashboard_tile_meta(): array {
    return [
        'requests'       => ['label' => 'Booking requests', 'sub' => 'waiting for a yes or no', 'href' => '/admin/holds.php?status=pending', 'tone' => 'warn'],
        'enquiries'      => ['label' => 'Enquiries to answer', 'sub' => 'new, or the guest replied', 'href' => '/admin/submissions.php', 'tone' => 'warn'],
        'conflicts'      => ['label' => 'Calendar conflicts', 'sub' => 'double bookings to sort out', 'href' => '/admin/conflicts.php', 'tone' => 'warn'],
        'guest_requests' => ['label' => 'Guest requests', 'sub' => 'not yet picked up', 'href' => '/admin/concierge-desk.php?status=requested', 'tone' => 'warn'],
        'messages'       => ['label' => 'Guest messages', 'sub' => 'unread', 'href' => '/admin/messages.php', 'tone' => 'warn'],
        'reservations'   => ['label' => 'Table bookings', 'sub' => 'waiting to be confirmed', 'href' => '/admin/reservations.php', 'tone' => 'warn'],
        'team_overdue'   => ['label' => 'Overdue tasks', 'sub' => 'across your team', 'href' => '/admin/tasks.php', 'tone' => 'warn'],
        'counts_review'  => ['label' => 'Stock counts', 'sub' => 'differences to review', 'href' => '/admin/inventory-counts.php', 'tone' => 'info'],
        'visitors'       => ['label' => 'Visitors on site', 'sub' => 'signed in, not yet out', 'href' => '/admin/gate.php', 'tone' => 'info'],
        'team_chat'      => ['label' => 'Team chat', 'sub' => 'unread', 'href' => '/admin/internal-messages.php', 'tone' => 'info'],
    ];
}

/**
 * The line above the tiles. "Nothing is waiting on you" is only said when it is
 * true: no tile that needs action is above zero AND the person has no task of
 * their own left open from an earlier day.
 */
function dashboard_heading(array $tiles, array $meta, int $myOverdue, int $myToday): string {
    foreach ($tiles as $k => $n) {
        if (($meta[$k]['tone'] ?? '') === 'warn' && (int)$n > 0) return 'Needs your attention';
    }
    if ($myOverdue > 0) return 'Needs your attention';
    return $myToday > 0 ? 'Today' : 'Nothing is waiting on you';
}

/**
 * The "Needs you" queue: what is above zero first (action items before the
 * merely informative), and everything at zero folded into one "all clear" line
 * instead of a wall of grey zeros. A tile that could not be read stays visible
 * with a dash — never silently counted as clear.
 * @return array{rows: array<int,array{key:string,n:?int,hot:bool}>, clear: string[]}
 */
function dashboard_queue(array $tiles, array $meta): array {
    $hot = []; $soft = []; $unknown = []; $clear = [];
    foreach ($tiles as $k => $n) {
        if (!isset($meta[$k])) continue;
        if ($n === null)      $unknown[] = ['key' => $k, 'n' => null, 'hot' => false];
        elseif ((int)$n <= 0) $clear[]   = $meta[$k]['label'];
        elseif ($meta[$k]['tone'] === 'warn') $hot[]  = ['key' => $k, 'n' => (int)$n, 'hot' => true];
        else                                  $soft[] = ['key' => $k, 'n' => (int)$n, 'hot' => false];
    }
    return ['rows' => array_merge($hot, $soft, $unknown), 'clear' => $clear];
}

/** The one-line summary under the greeting, in plain words. */
function dashboard_headline(string $kind, ?int $arrivals, ?int $departures, int $needs, int $myToday, int $myOverdue): string {
    $tasks = function () use ($myToday, $myOverdue): string {
        if ($myToday + $myOverdue === 0) return 'No tasks for you today.';
        $s = $myToday > 0 ? 'You have ' . $myToday . ' task' . ($myToday === 1 ? '' : 's') . ' today' : 'Nothing new today';
        return $s . ($myOverdue > 0 ? ', and ' . $myOverdue . ' overdue.' : '.');
    };
    if ($kind === 'ops' || $kind === 'store') return $tasks();
    if ($kind === 'pos') return 'The till is ready when you are. ' . $tasks();
    $arr = $arrivals === null ? '' : ($arrivals === 0 ? 'No arrivals today' : ($arrivals === 1 ? '1 guest arrives today' : $arrivals . ' guests arrive today'));
    if ($kind === 'security') {
        $dep = $departures === null ? '' : ($departures === 1 ? '1 leaves' : (int)$departures . ' leave');
        return $arr === '' ? $tasks() : $arr . ($dep !== '' ? ', ' . $dep : '') . '.';
    }
    $need = $needs === 0 ? 'nothing is waiting on you' : ($needs === 1 ? '1 thing needs you' : $needs . ' things need you');
    return $arr === '' ? ucfirst($need) . '.' : $arr . ' and ' . $need . '.';
}

/** Initials for a guest's avatar: "Amina Njoroge" → "AN", "Cher" → "CH". */
function dashboard_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$parts) return '?';
    $first = mb_substr($parts[0], 0, 1);
    $last  = count($parts) > 1 ? mb_substr((string)end($parts), 0, 1) : mb_substr($parts[0], 1, 1);
    return mb_strtoupper($first . $last);
}

/** Rooms in use tonight as a whole percent; null when there are no rooms to measure against. */
function dashboard_occupancy_pct(int $inHouse, int $rooms): ?int {
    if ($rooms <= 0) return null;
    return (int)round(min(100, max(0, $inHouse / $rooms * 100)));
}

/**
 * Revenue per arrival day for the bar chart, in ONE currency (the one with the
 * most revenue in the window) — bars of mixed currencies would mean nothing.
 * @param array $rows ledger rows (bookings_in_window): check_in, gross_amount, currency
 * @return array{currency:string, days:array<string,float>, max:float}
 */
function dashboard_daily_series(array $rows, string $fromYmd, string $toYmd): array {
    $by = [];
    foreach ($rows as $r) {
        $c = strtoupper(trim((string)($r['currency'] ?? ''))) ?: 'USD';
        $by[$c] = ($by[$c] ?? 0.0) + (float)($r['gross_amount'] ?? 0);
    }
    if (!$by || max($by) <= 0) return ['currency' => '', 'days' => [], 'max' => 0.0];
    arsort($by);
    $cur  = (string)array_key_first($by);
    $days = [];
    for ($t = strtotime($fromYmd); $t <= strtotime($toYmd); $t = strtotime('+1 day', $t)) $days[date('Y-m-d', $t)] = 0.0;
    foreach ($rows as $r) {
        $c = strtoupper(trim((string)($r['currency'] ?? ''))) ?: 'USD';
        $d = substr((string)($r['check_in'] ?? ''), 0, 10);
        if ($c === $cur && isset($days[$d])) $days[$d] += (float)($r['gross_amount'] ?? 0);
    }
    return ['currency' => $cur, 'days' => $days, 'max' => (float)max($days)];
}

/** One dashboard icon as inline SVG (stroke, 24×24 grid). Unknown name → a plain dot. */
function dashboard_icon(string $name, int $size = 18): string {
    static $p = [
        'plus'         => '<path d="M12 5v14M5 12h14"/>',
        'calendar'     => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'file'         => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/>',
        'coin'         => '<path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'chart'        => '<path d="M18 20V10M12 20V4M6 20v-6"/>',
        'clipboard'    => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1M9 11h6M9 15h4"/>',
        'check-square' => '<path d="m9 11 3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        'clock'        => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'shield'       => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'lock'         => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'bell'         => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.300 21a1.940 1.940 0 0 0 3.400 0"/>',
        'sparkle'      => '<path d="m12 3 1.900 4.600L18.500 9l-4.600 1.900L12 15.500l-1.900-4.600L5.500 9l4.600-1.400z"/>',
        'chevron'      => '<path d="m9 18 6-6-6-6"/>',
        'check'        => '<path d="M20 6 9 17l-5-5"/>',
        'till'         => '<rect x="2" y="4" width="20" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
        'box'          => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
        'settings'     => '<circle cx="12" cy="12" r="3"/><path d="M19.400 15a1.650 1.650 0 0 0 .330 1.820l.060.060a2 2 0 1 1-2.830 2.830l-.060-.060a1.650 1.650 0 0 0-1.820-.330 1.650 1.650 0 0 0-1 1.510V21a2 2 0 0 1-4 0v-.090A1.650 1.650 0 0 0 9 19.400a1.650 1.650 0 0 0-1.820.330l-.060.060a2 2 0 1 1-2.830-2.830l.060-.060A1.650 1.650 0 0 0 4.680 15a1.650 1.650 0 0 0-1.510-1H3a2 2 0 0 1 0-4h.090A1.650 1.650 0 0 0 4.600 9a1.650 1.650 0 0 0-.330-1.820l-.060-.060a2 2 0 1 1 2.830-2.830l.060.060A1.650 1.650 0 0 0 9 4.680a1.650 1.650 0 0 0 1-1.510V3a2 2 0 0 1 4 0v.090a1.650 1.650 0 0 0 1 1.510 1.650 1.650 0 0 0 1.820-.330l.060-.060a2 2 0 1 1 2.830 2.830l-.060.060A1.650 1.650 0 0 0 19.400 9a1.650 1.650 0 0 0 1.510 1H21a2 2 0 0 1 0 4h-.090a1.650 1.650 0 0 0-1.510 1z"/>',
    ];
    return '<svg class="dz-ic" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.9"'
         . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '<circle cx="12" cy="12" r="2"/>') . '</svg>';
}

// ───────────────────────────── data (scoped, fail-soft) ────────────────────

/** Run a lookup; a missing table or a failed query yields $fallback, never an error. */
function dashboard_safe(callable $fn, mixed $fallback = null): mixed {
    try { return $fn(); }
    catch (Throwable $e) { error_log('[dashboard] ' . $e->getMessage()); return $fallback; }
}

/**
 * " AND <col> IN (…)" for a property list: null = every property (owner) → '',
 * an empty list = assigned nowhere → matches nothing. The list is passed in —
 * never read from the session here — so a caller's scope is the only scope.
 */
function dashboard_venue_and(string $col, ?array $venueIds): string {
    if ($venueIds === null) return '';
    if (!$venueIds) return ' AND 1=0';
    return ' AND ' . $col . ' IN (' . implode(',', array_map('intval', $venueIds)) . ')';
}

/** Enquiries have no property of their own: one with no room reaches every desk (admin/submissions.php). */
function dashboard_enquiry_and(?array $venueIds, string $alias = 's'): string {
    if ($venueIds === null) return '';
    return " AND ({$alias}.room_id IS NULL OR EXISTS (SELECT 1 FROM rooms er WHERE er.id = {$alias}.room_id"
         . dashboard_venue_and('er.venue_id', $venueIds) . '))';
}

/**
 * The number on each requested tile. null = could not be read (shown as a dash).
 * @param string[] $keys
 * @return array<string,?int>
 */
function dashboard_tile_values(array $keys, ?array $venueIds, int $adminId, string $todayYmd): array {
    $holdFrom = "FROM holds h JOIN units u ON u.id = h.unit_id JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u');
    $lookups = [
        // A web request carries a 24 h expiry; one past it is no longer waiting on anyone.
        'requests' => fn() => (int)db_query("SELECT COUNT(*) {$holdFrom} WHERE h.status = 'pending'
            AND (h.expires_at IS NULL OR h.expires_at > NOW())" . dashboard_venue_and('r.venue_id', $venueIds))->fetchColumn(),
        // Same property rule as admin/submissions.php: an enquiry with no room reaches every desk.
        'enquiries' => function () use ($venueIds) {
            $new = function_exists('submission_status_supported') && submission_status_supported()
                ? (int)db_query("SELECT COUNT(*) FROM submissions s WHERE s.status = 'received'" . dashboard_enquiry_and($venueIds))->fetchColumn() : 0;
            return $new + (function_exists('submission_unread_reply_count') ? (int)submission_unread_reply_count() : 0);
        },
        'conflicts' => fn() => (int)db_query("SELECT COUNT(*) FROM channel_conflicts c JOIN units u ON u.id = c.unit_id
            JOIN rooms r ON r.id = u.room_id WHERE c.status = 'pending'" . dashboard_venue_and('r.venue_id', $venueIds))->fetchColumn(),
        'guest_requests' => fn() => (int)db_query("SELECT COUNT(*) FROM booking_addons ba JOIN holds h ON h.id = ba.hold_id
            JOIN units u ON u.id = h.unit_id JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u') . "
            WHERE ba.status = 'requested'" . dashboard_venue_and('r.venue_id', $venueIds))->fetchColumn(),
        'messages'      => fn() => function_exists('count_unread_admin') ? (int)count_unread_admin($venueIds) : 0,
        'team_chat'     => fn() => function_exists('internal_unread_total') ? (int)internal_unread_total($adminId) : 0,
        'reservations'  => fn() => (int)(reservation_dashboard_counts($venueIds)['pending'] ?? 0),
        'team_overdue'  => fn() => (int)db_query("SELECT COUNT(*) FROM tasks t WHERE t.status IN ('todo','in_progress')
            AND t.due_date IS NOT NULL AND t.due_date < :d" . dashboard_venue_and('t.venue_id', $venueIds), [':d' => $todayYmd])->fetchColumn(),
        'counts_review' => fn() => (int)inv_count_queue_size($venueIds, true),
        'visitors'      => fn() => count(fetch_visitors($venueIds, true)),
    ];
    $out = [];
    foreach ($keys as $k) $out[$k] = isset($lookups[$k]) ? dashboard_safe($lookups[$k]) : null;
    return $out;
}

/** Today's arrivals, departures and guests in house — the Front desk's own data. */
function dashboard_today(?array $venueIds, string $todayYmd): array {
    return dashboard_safe(fn() => frontdesk_day($venueIds, $todayYmd), ['arriving' => [], 'inhouse' => [], 'departing' => [], 'kpi_inhouse' => 0]);
}

/**
 * This month so far, from the bookings ledger and the POS — the same aggregators
 * admin/reports.php uses, so the figures match the Reports page. Money is never
 * summed across currencies: one entry per currency.
 * @return array{supported:bool, month:string, revenue:array, occupancy:?float, pos_today:array, series:array}
 */
function dashboard_money(?array $venueIds, array $admin, string $todayYmd): array {
    $from = substr($todayYmd, 0, 8) . '01';
    $out  = ['supported' => false, 'month' => date('F', (int)strtotime($todayYmd)), 'revenue' => [], 'occupancy' => null, 'pos_today' => [],
             'series' => ['currency' => '', 'days' => [], 'max' => 0.0]];
    dashboard_safe(function () use (&$out, $venueIds, $from, $todayYmd) {
        if (!function_exists('bookings_supported') || !bookings_supported()) return;
        $rows = bookings_in_window($venueIds, $from, $todayYmd);
        $out['supported'] = true;
        foreach (bookings_summarize($rows)['currencies'] as $cur => $t) {
            $out['revenue'][] = ['amount' => bookings_money((float)$t['revenue'], (string)$cur), 'bookings' => (int)$t['bookings']];
        }
        $out['occupancy'] = (float)bookings_occupancy($rows, $from, $todayYmd, bookings_active_unit_count($venueIds))['pct'];
        $out['series']    = dashboard_daily_series($rows, $from, $todayYmd);
    });
    dashboard_safe(function () use (&$out, $admin, $todayYmd) {
        if (!pos_supported()) return;
        require_once __DIR__ . '/pos.php';
        $outlets = pos_manageable_outlet_ids($admin);
        if (!$outlets) return;
        foreach (pos_report_summarize(pos_report_sales($outlets, $todayYmd, $todayYmd))['currencies'] as $cur => $t) {
            $out['pos_today'][] = ['amount' => bookings_money($t['sales'] / 100, (string)$cur), 'sales' => (int)$t['n']];
        }
    });
    return $out;
}

/**
 * Rooms that can be slept in tonight in the account's properties — active units
 * of ordinary rooms (a whole-property listing is the same beds sold another way,
 * so it is not counted twice). Feeds the occupancy ring. 0 when it cannot be read.
 */
function dashboard_room_count(?array $venueIds): int {
    return (int)dashboard_safe(fn() => (int)db_query("SELECT COUNT(*) FROM units u JOIN rooms r ON r.id = u.room_id
        WHERE u.is_active = TRUE AND COALESCE(r.is_entire_place, FALSE) = FALSE" . dashboard_venue_and('r.venue_id', $venueIds))->fetchColumn(), 0);
}

/** The signed-in person's own tasks: today's, plus anything still open from before. */
function dashboard_my_tasks(int $adminId, string $todayYmd): array {
    return [
        'today'   => dashboard_safe(fn() => task_user_day_fetch($adminId, $todayYmd), []),
        'overdue' => dashboard_safe(fn() => task_user_overdue_fetch($adminId, $todayYmd), []),
    ];
}

/** The newest enquiries this account may open (same property rule as the Enquiries page). */
function dashboard_recent_enquiries(?array $venueIds, int $limit = 5): array {
    return dashboard_safe(function () use ($venueIds, $limit) {
        return db_query("SELECT s.id, s.type, s.guest_name, s.check_in, s.check_out, s.created_at, r.name AS room_name
                           FROM submissions s LEFT JOIN rooms r ON r.id = s.room_id
                          WHERE TRUE" . dashboard_enquiry_and($venueIds) . "
                          ORDER BY s.created_at DESC LIMIT " . max(1, $limit))->fetchAll();
    }, []);
}
