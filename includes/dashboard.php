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
 * @return array{label:string, badge:string, summary:string, can:string[], cannot:string}
 */
function dashboard_profile(string $kind, ?string $job, array $venueNames): array {
    $where = match (true) {
        $kind === 'owner'        => 'every property',
        count($venueNames) === 0 => 'no property yet — ask the owner to assign you one',
        count($venueNames) === 1 => $venueNames[0],
        default                  => implode(', ', array_slice($venueNames, 0, -1)) . ' and ' . end($venueNames),
    };
    $jobLabel = ['housekeeping' => 'Housekeeping', 'laundry' => 'Laundry', 'maintenance' => 'Maintenance', 'gardening' => 'Gardening',
                 'driver' => 'Driver', 'security' => 'Gate security', 'shop' => 'Shop', 'spa' => 'Salon & Spa', 'kite' => 'Kite school'][$job ?? ''] ?? 'Front desk';
    return match ($kind) {
        'owner' => ['label' => 'Owner', 'badge' => 'teal',
            'summary' => 'You have full access to every property and every setting.',
            'can' => ['Confirm or decline booking requests', 'Set rates and send quotes', 'Edit properties, rooms and the website',
                      'Manage staff, travel agents and logins', 'See reports, invoices and the accounts', 'Change settings, emails and the AI assistant'],
            'cannot' => ''],
        'manager' => ['label' => 'Manager', 'badge' => 'green',
            'summary' => "You run day-to-day operations at {$where}.",
            'can' => ['See arrivals, departures and guest requests', 'Give out tasks and set job timetables', 'Record attendance',
                      'Run the restaurant: menus, hours and table bookings', 'Manage the till, stock and inventory', 'See reports for your properties'],
            'cannot' => 'Prices, the website and staff logins are set by the owner.'],
        'reception' => ['label' => 'Reception', 'badge' => 'orange',
            'summary' => "You handle bookings and guests at {$where}.",
            'can' => ['Confirm or decline booking requests', 'Answer enquiries and send quotes', 'Use the calendar and sort out conflicts',
                      'Look after guest requests and messages', 'Take table reservations', 'Sign visitors in at the gate'],
            'cannot' => 'Prices, menus and reports are handled by the owner or a manager.'],
        'security' => ['label' => $jobLabel, 'badge' => 'blue',
            'summary' => "You look after the gate at {$where}.",
            'can' => ['See who is arriving and leaving today', 'Sign visitors in and out', 'See your own tasks and timetable', 'Chat with the team'],
            'cannot' => ''],
        'pos' => ['label' => $jobLabel, 'badge' => 'blue',
            'summary' => 'You sell at the till.',
            'can' => ['Open the till and take payments', 'Charge a sale to a guest\'s room', 'Set your own till PIN', 'Chat with the team'],
            'cannot' => ''],
        'ops' => ['label' => $jobLabel, 'badge' => 'blue',
            'summary' => "Your work at {$where}.",
            'can' => ['See today\'s tasks and mark them done', 'See your week on the timetable', 'Count stock when a count is due', 'Chat with the team'],
            'cannot' => ''],
        default => ['label' => $jobLabel, 'badge' => 'blue',
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
 * @return array{tiles:string[], sections:string[], shortcuts:array<int,array{0:string,1:string}>}
 */
function dashboard_plan(string $kind, array $has = []): array {
    $on = fn(string $k): bool => !empty($has[$k]);
    $plan = match ($kind) {
        'owner' => [
            'tiles'     => ['requests', 'enquiries', 'conflicts', 'guest_requests', 'messages', 'reservations', 'team_overdue', 'counts_review'],
            'sections'  => ['today', 'money', 'my_tasks', 'recent_enquiries'],
            'shortcuts' => [['New booking', '/admin/hold-new.php'], ['Calendar', '/admin/gantt.php'], ['Quote builder', '/admin/quote-builder.php'],
                            ['Rates', '/admin/rates.php'], ['Reports', '/admin/reports.php']],
        ],
        'manager' => [
            'tiles'     => ['guest_requests', 'messages', 'reservations', 'team_overdue', 'counts_review'],
            'sections'  => ['today', 'money', 'my_tasks'],
            'shortcuts' => [['Front desk', '/admin/frontdesk.php'], ['Tasks', '/admin/tasks.php'], ['Attendance', '/admin/attendance.php'],
                            ['Reports', '/admin/reports.php']],
        ],
        'reception' => [
            'tiles'     => ['requests', 'enquiries', 'conflicts', 'guest_requests', 'messages', 'reservations'],
            'sections'  => ['today', 'my_tasks', 'recent_enquiries'],
            'shortcuts' => [['New booking', '/admin/hold-new.php'], ['Calendar', '/admin/gantt.php'], ['Quote builder', '/admin/quote-builder.php'],
                            ['Front desk', '/admin/frontdesk.php']],
        ],
        'security' => [
            'tiles'     => ['visitors'],
            'sections'  => ['today', 'my_tasks'],
            'shortcuts' => [['Gate', '/admin/gate.php'], ['Timetable', '/admin/timetable.php']],
        ],
        'pos' => [
            'tiles'     => [],
            'sections'  => ['till', 'my_tasks'],
            'shortcuts' => [['My till PIN', '/admin/pos-pins.php'], ['Timetable', '/admin/timetable.php']],
        ],
        'ops' => [
            'tiles'     => [],
            'sections'  => ['my_tasks', 'counts_due'],
            'shortcuts' => [['My work', '/admin/mywork.php'], ['Timetable', '/admin/timetable.php']],
        ],
        default => [
            'tiles'     => ['guest_requests', 'messages'],
            'sections'  => ['today', 'my_tasks'],
            'shortcuts' => [['Front desk', '/admin/frontdesk.php'], ['Guest requests', '/admin/concierge-desk.php'], ['Timetable', '/admin/timetable.php']],
        ],
    };
    $plan['tiles'][] = 'team_chat';   // every account has the team chat

    // Drop what an optional module would show when that module is not installed.
    $needs = ['reservations' => 'reservations', 'counts_review' => 'inventory', 'team_overdue' => 'tasks', 'visitors' => 'visitors'];
    $plan['tiles'] = array_values(array_filter($plan['tiles'], fn($t) => !isset($needs[$t]) || $on($needs[$t])));
    $secNeeds = ['counts_due' => 'inventory', 'till' => 'pos', 'my_tasks' => 'tasks'];
    $plan['sections'] = array_values(array_filter($plan['sections'], fn($s) => !isset($secNeeds[$s]) || $on($secNeeds[$s])));
    if ($on('ai') && in_array($kind, ['owner', 'manager', 'reception', 'frontdesk'], true)) $plan['shortcuts'][] = ['AI assistant', '/admin/assistant.php'];
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
 * @return array{supported:bool, month:string, revenue:array, occupancy:?float, pos_today:array}
 */
function dashboard_money(?array $venueIds, array $admin, string $todayYmd): array {
    $from = substr($todayYmd, 0, 8) . '01';
    $out  = ['supported' => false, 'month' => date('F', (int)strtotime($todayYmd)), 'revenue' => [], 'occupancy' => null, 'pos_today' => []];
    dashboard_safe(function () use (&$out, $venueIds, $from, $todayYmd) {
        if (!function_exists('bookings_supported') || !bookings_supported()) return;
        $rows = bookings_in_window($venueIds, $from, $todayYmd);
        $out['supported'] = true;
        foreach (bookings_summarize($rows)['currencies'] as $cur => $t) {
            $out['revenue'][] = ['amount' => bookings_money((float)$t['revenue'], (string)$cur), 'bookings' => (int)$t['bookings']];
        }
        $out['occupancy'] = (float)bookings_occupancy($rows, $from, $todayYmd, bookings_active_unit_count($venueIds))['pct'];
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
