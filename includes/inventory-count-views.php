<?php
declare(strict_types=1);
/**
 * Inventory counts — who may count and resolve, what is due, count sheets and the
 * manager's review queue. Counting and resolving themselves are the Plan 1 core
 * (inv_count_start / inv_count_submit / inv_count_resolve_line): counting never
 * moves stock; only a manager's resolution does.
 * Test: php tests/inventory_counts_logic.php
 */

require_once __DIR__ . '/inventory-views.php';
require_once __DIR__ . '/frontdesk.php';     // frontdesk_today_ymd() — Nairobi "today"

const INV_COUNT_STATUS_ORDER  = ['overdue' => 0, 'due' => 1, 'ok' => 2, 'manual' => 3];
const INV_COUNT_STATUS_LABELS = ['manual' => ['Counted by hand', 'badge--grey'], 'ok' => ['Up to date', 'badge--green'],
                                 'due' => ['Due', 'badge--orange'], 'overdue' => ['Overdue', 'badge--red']];
const INV_RESOLUTION_LABELS   = ['missing' => 'Missing', 'broken' => 'Broken', 'stolen' => 'Stolen', 'found' => 'Found',
                                 'recount' => 'Recount', 'accepted' => 'Matched'];

// ── Pure rules ──────────────────────────────────────────────────────────────

/**
 * May this account COUNT a place? — PURE. Owner: anywhere. Otherwise, a venue-bound
 * place (a property or its area) is counted only by someone who currently works
 * there — anyone whose venues include it — never by a stale `count_assignee_id`
 * left over from before they moved property. The responsible-person bypass applies
 * ONLY to SHARED places (Main stock, venue-less outlets), alongside managers.
 * Never a person's location.
 */
function inv_can_count(array $loc, int $adminId, string $role, ?array $venueIds): bool {
    if (($loc['kind'] ?? '') === 'person') return false;
    if ($venueIds === null || $role === 'owner') return true;
    $v = $loc['venue_id'] ?? null;
    if ($v === null || $v === '') return $role === 'manager' || ($adminId > 0 && (int)($loc['count_assignee_id'] ?? 0) === $adminId);
    return in_array((int)$v, array_map('intval', $venueIds), true);
}

/** May this account RESOLVE gaps at a place? The owner, or a manager of that property — PURE. */
function inv_can_resolve(array $loc, string $role, ?array $venueIds): bool {
    if ($role === 'owner' || $venueIds === null) return true;
    return $role === 'manager' && inv_location_editable($loc, $venueIds);
}

/** Most urgent first: overdue, due, up to date, manual; then by label — PURE. */
function inv_count_sort(array $rows): array {
    usort($rows, fn(array $a, array $b): int =>
        [INV_COUNT_STATUS_ORDER[$a['count_status']] ?? 9, mb_strtolower((string)($a['label'] ?? $a['name']))]
        <=> [INV_COUNT_STATUS_ORDER[$b['count_status']] ?? 9, mb_strtolower((string)($b['label'] ?? $b['name']))]);
    return $rows;
}

/** A count line's gap (counted − expected) and the value of that gap — PURE. */
function inv_count_line_gap(array $line): array {
    $gap = (int)$line['counted'] - (int)$line['expected'];
    $rv  = $line['replacement_value'] ?? null;
    return ['gap' => $gap, 'value' => ($rv === null || $rv === '') ? null : round(abs($gap) * (float)$rv, 2)];
}

// ── Reads ───────────────────────────────────────────────────────────────────

/**
 * SQL subqueries for a location id column $loc (e.g. 'l.id'; never a placeholder —
 * it appears twice). line_count = how many lines a count started now would have
 * (the rows inv_count_start() snapshots: switched-on items with stock or a par);
 * open_count_id = today's open count, if any (Nairobi "today" — the DB session
 * runs in Africa/Nairobi).
 */
function inv_count_state_sql(string $loc): string {
    return "(SELECT COUNT(*) FROM inv_balances b JOIN inv_items i ON i.id = b.item_id
              WHERE b.location_id = {$loc} AND i.is_active = TRUE AND (b.qty <> 0 OR b.par_qty IS NOT NULL)) AS line_count,
            (SELECT c.id FROM inv_counts c WHERE c.location_id = {$loc} AND c.status = 'open' AND c.started_at::date = CURRENT_DATE
              ORDER BY c.id DESC LIMIT 1) AS open_count_id";
}

/** One place's count state: ['line_count' => int, 'open_count_id' => ?int]. Does NO scoping. */
function inv_count_location_state(int $locationId): array {
    if (!inv_supported()) return ['line_count' => 0, 'open_count_id' => null];
    $r = db_query('SELECT ' . inv_count_state_sql('l.id') . ' FROM inv_locations l WHERE l.id = :l', [':l' => $locationId])->fetch();
    return ['line_count' => (int)($r['line_count'] ?? 0), 'open_count_id' => ($r['open_count_id'] ?? null) !== null ? (int)$r['open_count_id'] : null];
}

/** Places this account can count, each with count_status + due_ymd + line_count + open_count_id, most urgent first. */
function inv_countable_locations(int $adminId, string $role, ?array $venueIds, string $todayYmd): array {
    if (!inv_supported()) return [];
    $p = [':me' => $adminId];
    $w = '(' . inv_visible_sql('l', $venueIds, $p) . ' OR l.count_assignee_id = :me)';
    $rows = db_query("SELECT l.*, pl.name AS parent_name, " . inv_count_state_sql('l.id') . "
                        FROM inv_locations l
                        LEFT JOIN inv_locations pl ON pl.id = l.parent_id
                       WHERE l.is_active = TRUE AND l.kind <> 'person' AND {$w}", $p)->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        if (!inv_can_count($r, $adminId, $role, $venueIds)) continue;
        $every = $r['count_every_days'] !== null ? (int)$r['count_every_days'] : null;
        $r['count_status'] = inv_count_status($r['last_counted_at'], $every, $todayYmd);
        $r['due_ymd']      = inv_count_due_ymd($r['last_counted_at'], $every);
        $r['label']        = inv_location_label($r);
        $out[] = $r;
    }
    return inv_count_sort($out);
}

/**
 * The places this account should count today (due or overdue). A scheduled place
 * with nothing expected there (line_count 0 — no balance and no par set) is never
 * "due": there is nothing to count, so it never nags anyone.
 */
function inv_counts_due(int $adminId, string $role, ?array $venueIds, string $todayYmd): array {
    return array_values(array_filter(inv_countable_locations($adminId, $role, $venueIds, $todayYmd),
        fn(array $r): bool => in_array($r['count_status'], ['due', 'overdue'], true) && (int)($r['line_count'] ?? 1) !== 0));
}

/**
 * A count with its place and lines (item details). null when it does not exist.
 * Does NO scoping — the caller checks inv_can_count / inv_can_resolve / the profile's venue scope.
 */
function inv_count_sheet(int $countId): ?array {
    if (!inv_supported() || $countId <= 0) return null;
    $c = db_query("SELECT c.*, l.name AS location_name, l.kind, l.venue_id, l.count_assignee_id, l.parent_id,
                          pl.name AS parent_name, a.name AS counted_by_name
                     FROM inv_counts c
                     JOIN inv_locations l       ON l.id = c.location_id
                     LEFT JOIN inv_locations pl ON pl.id = l.parent_id
                     LEFT JOIN admin_users a    ON a.id = c.counted_by
                    WHERE c.id = :c", [':c' => $countId])->fetch();
    if (!$c) return null;
    $c['lines'] = db_query("SELECT cl.*, i.name, i.image_key, i.icon, i.unit_label, i.tracking, i.replacement_value, i.currency, i.category
                              FROM inv_count_lines cl JOIN inv_items i ON i.id = cl.item_id
                             WHERE cl.count_id = :c ORDER BY i.category NULLS LAST, i.name", [':c' => $countId])->fetchAll();
    return $c;
}

/**
 * SQL: is location alias $a in one of the account's OWN venues? Unlike
 * inv_visible_sql(), there is no shared branch (Main stock / venue-less outlets
 * never match) — an empty venue list is FALSE, never TRUE. Owner ($venueIds =
 * null) is not handled here; callers keep the owner path unrestricted.
 */
function inv_own_venues_sql(string $a, array $venueIds, array &$p, string $tag = 'own'): string {
    $ph = [];
    foreach (array_values($venueIds) as $i => $v) { $ph[] = ":{$tag}{$i}"; $p[":{$tag}{$i}"] = (int)$v; }
    return $ph ? "{$a}.venue_id IN (" . implode(',', $ph) . ')' : 'FALSE';
}

/**
 * Submitted counts with open gaps at places the account can see, oldest first.
 * $resolvableOnly=true restricts further to places the account can actually
 * RESOLVE — its own venues only, no shared branch (Main stock is owner-resolved) —
 * which is what a badge count should reflect. The review page itself passes the
 * default (false) so it can list everything visible and mark the rows it can't act
 * on ("The owner checks this place"); the Front Desk "N to review" badge passes true.
 */
function inv_count_queue(?array $venueIds, bool $resolvableOnly = false): array {
    if (!inv_supported()) return [];
    $p = [];
    $w = ($resolvableOnly && $venueIds !== null) ? inv_own_venues_sql('l', $venueIds, $p) : inv_visible_sql('l', $venueIds, $p);
    return db_query("SELECT c.id, c.location_id, c.submitted_at, c.counted_by, l.name AS location_name, l.kind, l.venue_id,
                            pl.name AS parent_name, a.name AS counted_by_name,
                            (SELECT COUNT(*) FROM inv_count_lines cl WHERE cl.count_id = c.id AND cl.resolution IS NULL) AS open_lines
                       FROM inv_counts c
                       JOIN inv_locations l       ON l.id = c.location_id
                       LEFT JOIN inv_locations pl ON pl.id = l.parent_id
                       LEFT JOIN admin_users a    ON a.id = c.counted_by
                      WHERE c.status = 'submitted' AND {$w}
                      ORDER BY c.submitted_at, c.id", $p)->fetchAll();
}

/** Count sibling of inv_count_queue() — see its docblock for $resolvableOnly. */
function inv_count_queue_size(?array $venueIds, bool $resolvableOnly = false): int {
    if (!inv_supported()) return 0;
    $p = [];
    $w = ($resolvableOnly && $venueIds !== null) ? inv_own_venues_sql('l', $venueIds, $p) : inv_visible_sql('l', $venueIds, $p);
    return (int) db_query("SELECT COUNT(*) FROM inv_counts c JOIN inv_locations l ON l.id = c.location_id
                            WHERE c.status = 'submitted' AND {$w}", $p)->fetchColumn();
}

/**
 * The place a count line belongs to (for the resolve permission check), plus
 * parent_name (for its label) and count_id (the count the line is on).
 * Does NO scoping — the caller checks inv_can_count / inv_can_resolve / the profile's venue scope.
 */
function inv_count_line_location(int $lineId): ?array {
    if (!inv_supported()) return null;
    $r = db_query('SELECT l.*, pl.name AS parent_name, c.id AS count_id
                     FROM inv_count_lines cl JOIN inv_counts c ON c.id = cl.count_id
                     JOIN inv_locations l ON l.id = c.location_id
                     LEFT JOIN inv_locations pl ON pl.id = l.parent_id
                    WHERE cl.id = :id', [':id' => $lineId])->fetch();
    return $r ?: null;
}

// ── Shared "counts due" card (My Work, Front Desk) ──────────────────────────

/**
 * A card listing places due/overdue for counting (from inv_countable_locations();
 * rows that are not due are skipped) and, for managers, how many counts wait for
 * review. Returns '' when there is nothing to show.
 */
function inv_counts_due_card(array $places, int $reviewCount): string {
    $due = array_values(array_filter($places, fn(array $r): bool =>
        in_array($r['count_status'], ['due', 'overdue'], true) && (int)($r['line_count'] ?? 1) !== 0));
    if (!$due && $reviewCount <= 0) return '';
    ob_start(); ?>
<div class="card" style="margin-bottom:16px">
  <div class="card__head"><span class="card__title">Stock counts</span>
    <?php if ($reviewCount > 0): ?><a href="/admin/inventory-counts.php" class="btn-outline btn-sm"><?= (int)$reviewCount ?> to review</a><?php endif; ?></div>
  <?php if ($due): ?>
  <div class="card__body" style="padding:0">
    <?php foreach ($due as $r): [$sl, $sc] = INV_COUNT_STATUS_LABELS[$r['count_status']]; ?>
    <div style="display:flex;align-items:center;gap:10px;justify-content:space-between;padding:12px 16px;border-top:1px solid var(--border)">
      <span><strong><?= e($r['label']) ?></strong> <span class="badge <?= e($sc) ?>"><?= e($sl) ?></span></span>
      <a href="/admin/inventory-count.php?<?= $r['open_count_id'] ? 'count=' . (int)$r['open_count_id'] : 'location=' . (int)$r['id'] ?>" class="btn-primary btn-sm"><?= $r['open_count_id'] ? 'Continue' : 'Count now' ?></a>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
    <?php
    return (string) ob_get_clean();
}
