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
                                 'due' => ['Due today', 'badge--orange'], 'overdue' => ['Overdue', 'badge--red']];
const INV_RESOLUTION_LABELS   = ['missing' => 'Missing', 'broken' => 'Broken', 'stolen' => 'Stolen', 'found' => 'Found',
                                 'recount' => 'Recount', 'accepted' => 'Matched'];

// ── Pure rules ──────────────────────────────────────────────────────────────

/**
 * May this account COUNT a place? — PURE. Owner: anywhere. The place's responsible
 * person: yes. Shared places (Main stock, venue-less outlets): managers too.
 * Otherwise anyone whose venues include the place's venue. Never a person's location.
 */
function inv_can_count(array $loc, int $adminId, string $role, ?array $venueIds): bool {
    if (($loc['kind'] ?? '') === 'person') return false;
    if ($venueIds === null || $role === 'owner') return true;
    if ($adminId > 0 && (int)($loc['count_assignee_id'] ?? 0) === $adminId) return true;
    $v = $loc['venue_id'] ?? null;
    if ($v === null || $v === '') return $role === 'manager';
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
        [INV_COUNT_STATUS_ORDER[$a['count_status']] ?? 9, mb_strtolower((string)$a['name'])]
        <=> [INV_COUNT_STATUS_ORDER[$b['count_status']] ?? 9, mb_strtolower((string)$b['name'])]);
    return $rows;
}

/** A count line's gap (counted − expected) and the value of that gap — PURE. */
function inv_count_line_gap(array $line): array {
    $gap = (int)$line['counted'] - (int)$line['expected'];
    $rv  = $line['replacement_value'] ?? null;
    return ['gap' => $gap, 'value' => ($rv === null || $rv === '') ? null : round(abs($gap) * (float)$rv, 2)];
}

// ── Reads ───────────────────────────────────────────────────────────────────

/** Places this account can count, each with count_status + due_ymd + open_count_id, most urgent first. */
function inv_countable_locations(int $adminId, string $role, ?array $venueIds, string $todayYmd): array {
    if (!inv_supported()) return [];
    $p = [':me' => $adminId];
    $w = '(' . inv_visible_sql('l', $venueIds, $p) . ' OR l.count_assignee_id = :me)';
    $rows = db_query("SELECT l.*, pl.name AS parent_name,
                             (SELECT COUNT(*) FROM inv_balances b WHERE b.location_id = l.id AND (b.qty <> 0 OR b.par_qty IS NOT NULL)) AS line_count,
                             (SELECT c.id FROM inv_counts c WHERE c.location_id = l.id AND c.status = 'open' ORDER BY c.id DESC LIMIT 1) AS open_count_id
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

/** The places this account should count today (due or overdue). */
function inv_counts_due(int $adminId, string $role, ?array $venueIds, string $todayYmd): array {
    return array_values(array_filter(inv_countable_locations($adminId, $role, $venueIds, $todayYmd),
        fn(array $r): bool => in_array($r['count_status'], ['due', 'overdue'], true)));
}

/** A count with its place and lines (item details). null when it does not exist. */
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

/** Submitted counts with open gaps at places the account can see, oldest first. */
function inv_count_queue(?array $venueIds): array {
    if (!inv_supported()) return [];
    $p = [];
    $w = inv_visible_sql('l', $venueIds, $p);
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

function inv_count_queue_size(?array $venueIds): int {
    if (!inv_supported()) return 0;
    $p = [];
    $w = inv_visible_sql('l', $venueIds, $p);
    return (int) db_query("SELECT COUNT(*) FROM inv_counts c JOIN inv_locations l ON l.id = c.location_id
                            WHERE c.status = 'submitted' AND {$w}", $p)->fetchColumn();
}

/** The place a count line belongs to (for the resolve permission check). */
function inv_count_line_location(int $lineId): ?array {
    if (!inv_supported()) return null;
    $r = db_query('SELECT l.* FROM inv_count_lines cl JOIN inv_counts c ON c.id = cl.count_id
                     JOIN inv_locations l ON l.id = c.location_id WHERE cl.id = :id', [':id' => $lineId])->fetch();
    return $r ?: null;
}

// ── Shared "counts due" card (My Work, Front Desk) ──────────────────────────

/**
 * A card listing places due/overdue for counting (from inv_countable_locations();
 * rows that are not due are skipped) and, for managers, how many counts wait for
 * review. Returns '' when there is nothing to show.
 */
function inv_counts_due_card(array $places, int $reviewCount): string {
    $due = array_values(array_filter($places, fn(array $r): bool => in_array($r['count_status'], ['due', 'overdue'], true)));
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
      <a href="/admin/inventory-count.php?location=<?= (int)$r['id'] ?>" class="btn-primary btn-sm"><?= $r['open_count_id'] ? 'Continue' : 'Count now' ?></a>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
    <?php
    return (string) ob_get_clean();
}
