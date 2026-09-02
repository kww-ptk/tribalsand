<?php
declare(strict_types=1);
/**
 * Tribal Sand — booking-flow add-ons ("upsells").
 *
 * An upsell IS a published activity (tours). Nothing here introduces a second
 * catalog: property assignment comes from tour_venues, pricing from
 * tours.price_amount / price_per_person, and the link to a booking from
 * booking_addons (kind='tour'). Migration: db/migrations/add_upsells.sql.
 *
 * Two switches decide where an activity is offered:
 *   venues.upsell_enabled    — per-property master switch (off by default)
 *   tours.upsell_placement   — none | enquiry | checkin | both
 *
 * Both surfaces (the enquiry wizard and the pre-arrival check-in wizard) read
 * through fetch_upsell_items(), so "already picked shouldn't show on the other
 * page" is a single rule in a single place.
 *
 * Every read is pre-migration-safe via upsells_supported().
 *
 * Depends on includes/db.php.
 */

require_once __DIR__ . '/db.php';

/** The surfaces an activity can be offered on. */
const UPSELL_PLACEMENTS = [
    'none'    => 'Not offered',
    'enquiry' => 'Enquiry form only',
    'checkin' => 'Pre-arrival check-in only',
    'both'    => 'Both',
];

/** True once add_upsells.sql is applied. Memoised; false pre-migration. */
function upsells_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db_query('SELECT upsell_enabled FROM venues LIMIT 1');
        db_query('SELECT upsell_placement FROM tours LIMIT 1');
        $ok = true;
    } catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** Is the booking-flow add-on feature switched on for this property? */
function upsell_venue_enabled(?int $venueId): bool {
    if (!$venueId || !upsells_supported()) return false;
    try {
        return (bool) db_query(
            'SELECT upsell_enabled FROM venues WHERE id = :v', [':v' => $venueId]
        )->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/** Tour ids already attached to a booking, so neither surface offers them twice. */
function upsell_addon_tour_ids(int $holdId): array {
    if ($holdId <= 0) return [];
    try {
        $ids = db_query(
            "SELECT DISTINCT tour_id FROM booking_addons
              WHERE hold_id = :h AND tour_id IS NOT NULL AND status <> 'cancelled'",
            [':h' => $holdId]
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return []; }
    return array_map('intval', $ids);
}

/**
 * Activities offered as add-ons at $venueId on $surface ('enquiry'|'checkin').
 *
 * Mirrors fetch_portal_activities()'s venue rule: an activity with no
 * tour_venues rows is offered everywhere, otherwise only at its own venues.
 * Returns [] when the property's master switch is off, pre-migration, or on any
 * error — this decorates a booking flow, so it must never be able to break one.
 *
 * $excludeHoldId drops anything already on that booking: the single rule behind
 * "if it's already picked it shouldn't show on the other page".
 */
function fetch_upsell_items(?int $venueId, string $surface, ?int $excludeHoldId = null): array {
    if (!in_array($surface, ['enquiry', 'checkin'], true)) return [];
    if (!upsell_venue_enabled($venueId)) return [];

    try {
        $rows = db_query(
            "SELECT t.id, t.slug, t.name, t.category, t.duration, t.short_desc,
                    t.price_amount, t.price_per_person, t.max_pax, t.upsell_placement,
                    (SELECT filename FROM tour_images ti
                      WHERE ti.tour_id = t.id AND ti.is_hero = TRUE LIMIT 1) AS hero
               FROM tours t
              WHERE t.is_published = TRUE
                AND t.upsell_placement IN (:s, 'both')
                AND (NOT EXISTS (SELECT 1 FROM tour_venues tv WHERE tv.tour_id = t.id)
                     OR EXISTS  (SELECT 1 FROM tour_venues tv WHERE tv.tour_id = t.id AND tv.venue_id = :v))
              ORDER BY t.sort_order ASC, t.name ASC",
            [':s' => $surface, ':v' => $venueId]
        )->fetchAll();
    } catch (Throwable $e) { return []; }

    if ($excludeHoldId) {
        $taken = upsell_addon_tour_ids($excludeHoldId);
        if ($taken) {
            $rows = array_values(array_filter($rows, fn($r) => !in_array((int)$r['id'], $taken, true)));
        }
    }
    return $rows;
}

/**
 * Keep only ids genuinely offered at $venueId on $surface, and return them as
 * price-snapshotted rows. The authority for what a guest may pick — a posted id
 * is never trusted, so a tampered form cannot attach another property's
 * activity, an unpublished one, or one that isn't offered on that surface.
 */
function upsell_validate_ids(array $postedIds, ?int $venueId, string $surface, ?int $excludeHoldId = null): array {
    $want = [];
    foreach ($postedIds as $v) { $i = (int)$v; if ($i > 0) $want[$i] = true; }
    if (!$want) return [];

    $out = [];
    foreach (fetch_upsell_items($venueId, $surface, $excludeHoldId) as $item) {
        if (isset($want[(int)$item['id']])) $out[] = $item;
    }
    return $out;
}

/** The compact shape stored on a submission's payload_json. */
function upsell_payload_row(array $item): array {
    return [
        'id'               => (int)$item['id'],
        'slug'             => (string)($item['slug'] ?? ''),
        'name'             => (string)($item['name'] ?? ''),
        'price_amount'     => $item['price_amount'] !== null ? (float)$item['price_amount'] : null,
        'price_per_person' => !empty($item['price_per_person']),
    ];
}

/** Human price label for a card: "$120 per person", "$400", or '' when unpriced. */
function upsell_price_label(array $item): string {
    $amt = $item['price_amount'] ?? null;
    if ($amt === null || (float)$amt <= 0) return '';
    $s = '$' . number_format((float)$amt, ((float)$amt == floor((float)$amt)) ? 0 : 2);
    return !empty($item['price_per_person']) ? $s . ' per person' : $s;
}

/**
 * Attach validated upsells to a booking as addon requests.
 *
 * Idempotent per tour: an activity already on the booking is skipped, so a
 * repeated convert or a guest ticking the same thing twice can't duplicate it.
 * Returns the number of rows created. Never throws — the caller is always in the
 * middle of something more important (creating a hold, saving a check-in), and
 * an add-on must not be able to fail that.
 */
function upsell_attach_to_hold(int $holdId, array $items, int $pax = 1): int {
    if ($holdId <= 0 || !$items) return 0;
    $existing = upsell_addon_tour_ids($holdId);
    $made = 0;
    foreach ($items as $item) {
        $tourId = (int)($item['id'] ?? 0);
        if ($tourId <= 0 || in_array($tourId, $existing, true)) continue;
        try {
            db_query(
                "INSERT INTO booking_addons (hold_id, kind, tour_id, details, status, pax, price_amount)
                 VALUES (:h, 'tour', :t, :d, 'requested', :p, :amt)",
                [
                    ':h'   => $holdId,
                    ':t'   => $tourId,
                    ':d'   => (string)($item['name'] ?? ''),
                    ':p'   => max(1, $pax),
                    ':amt' => $item['price_amount'] !== null ? (float)$item['price_amount'] : null,
                ]
            );
            $existing[] = $tourId;
            $made++;
        } catch (Throwable $e) {
            error_log('[upsell] attach failed for tour ' . $tourId . ' on hold ' . $holdId . ': ' . $e->getMessage());
        }
    }
    return $made;
}
