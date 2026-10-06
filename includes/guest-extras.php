<?php
declare(strict_types=1);
/**
 * Guest extras — what each property offers its guests to add to their stay
 * (guest portal design 1 "Postcard", Oct 2026).
 *
 * An extra IS an existing activity (tours — activities, wellness, water sports)
 * or an existing transfer option (service_options, service 'transfer'). Nothing
 * here is a second catalogue or a second price: prices come from those rows, and
 * a guest's pick is written by the same endpoint the portal always used
 * (api/booking-addon.php → booking_addons), so staff confirm it in the usual
 * places and a confirmed, priced extra lands on the bill.
 *
 * The owner curates per property in Admin → Properties → Guest extras
 * (migration add_venue_extras.sql → venue_extras): shown or not, order, featured
 * (home screen + emails, at most GUEST_EXTRAS_MAX_FEATURED), and when to offer it
 * (before arrival / during the stay / any time). The property's master switch is
 * the existing venues.upsell_enabled.
 *
 * A property with NO venue_extras rows (or a database before the migration) is
 * "not curated": it offers everything it offered before — every activity its
 * guests could request plus the transfers marked "Offer when booking" — with the
 * booking-flow add-ons featured. So shipping this changes nothing until the owner
 * saves a list.
 *
 * Pure functions are marked; the rest read the database and never throw.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/upsells.php';
require_once __DIR__ . '/booking.php';

const GUEST_EXTRAS_WHEN = ['any' => 'Any time', 'before' => 'Before arrival', 'during' => 'During the stay'];
const GUEST_EXTRAS_MAX_FEATURED = 3;
/** Time-of-day choices offered when booking an activity, and the clock time stored. */
const GUEST_EXTRAS_PARTS_OF_DAY = ['morning' => ['Morning', '09:00'], 'afternoon' => ['Afternoon', '14:00'], 'evening' => ['Evening', '18:00']];

/** True once add_venue_extras.sql has run (catalog lookups — safe inside a transaction). */
function guest_extras_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool)db_query("SELECT to_regclass('public.venue_extras') IS NOT NULL")->fetchColumn()
           && (bool)db_query("SELECT 1 FROM information_schema.columns WHERE table_name = 'holds' AND column_name = 'extras_reminder_sent_at'")->fetchColumn();
    } catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** Is the property's master switch on? (No add_upsells column = on: the old portal always offered activities.) */
function guest_extras_enabled(?int $venueId): bool {
    if (!$venueId) return false;
    return upsells_supported() ? upsell_venue_enabled($venueId) : true;
}

/** Stable key for an extra: "tour:12" / "transfer:3". Pure. */
function guest_extra_key(string $kind, int $refId): string { return $kind . ':' . $refId; }

/** Plain words for an activity category. Pure. */
function guest_extra_category_label(string $category, string $kind): string {
    if ($kind === 'transfer') return 'Transfers';
    $c = strtolower(trim($category));
    return ['wellness' => 'Wellness', 'water' => 'On the water', 'marine' => 'On the water', 'watersports' => 'On the water', 'kite' => 'On the water',
            'classic' => 'Safaris', 'custom' => 'Safaris', 'excursion' => 'Excursions', 'dining' => 'Dining',
            'culture' => 'Culture'][$c] ?? ($c !== '' ? ucfirst($c) : 'Activities');
}

/** Is a DB boolean true? Pure. */
function guest_extra_bool($v): bool { return $v === true || $v === 't' || $v === 'true' || $v === 1 || $v === '1'; }

/**
 * Everything this property COULD offer: its activities (the portal's venue rule)
 * and the active transfer options, shaped alike. Never throws.
 * @return list<array> rows with key, kind, ref_id, slug, name, category, group, duration, desc, price, per_person, max_pax, image, legacy_featured
 */
function guest_extras_catalogue(int $venueId): array {
    $out = [];
    $upsellIds = [];
    try { foreach (fetch_upsell_items($venueId, 'checkin') as $u) $upsellIds[(int)$u['id']] = true; } catch (Throwable $e) {}
    try { foreach (fetch_upsell_items($venueId, 'enquiry') as $u) $upsellIds[(int)$u['id']] = true; } catch (Throwable $e) {}
    foreach (fetch_portal_activities($venueId) as $t) {
        $out[] = [
            'key' => guest_extra_key('tour', (int)$t['id']), 'kind' => 'tour', 'ref_id' => (int)$t['id'], 'slug' => (string)$t['slug'],
            'name' => (string)$t['name'], 'category' => (string)$t['category'],
            'group' => guest_extra_category_label((string)$t['category'], 'tour'),
            'duration' => trim((string)($t['duration'] ?? '')), 'desc' => trim((string)($t['short_desc'] ?? '')),
            'price' => is_priced($t['price_amount'] ?? null) ? (float)$t['price_amount'] : null,
            'per_person' => guest_extra_bool($t['price_per_person'] ?? false),
            'max_pax' => max(0, (int)($t['max_pax'] ?? 0)),
            'image' => trim((string)($t['hero'] ?? '')) !== '' ? storage_url((string)$t['hero']) : '',
            'legacy_featured' => isset($upsellIds[(int)$t['id']]),
        ];
    }
    try {
        $offered = upsell_transfers_supported();
        $rows = db_query("SELECT id, label, price_amount" . ($offered ? ", offer_at_booking" : "") . "
                            FROM service_options WHERE service = 'transfer' AND is_active = TRUE ORDER BY sort_order ASC, id ASC")->fetchAll();
        foreach ($rows as $r) {
            $out[] = [
                'key' => guest_extra_key('transfer', (int)$r['id']), 'kind' => 'transfer', 'ref_id' => (int)$r['id'], 'slug' => '',
                'name' => (string)$r['label'], 'category' => 'transfer', 'group' => 'Transfers',
                'duration' => '', 'desc' => '', 'price' => is_priced($r['price_amount'] ?? null) ? (float)$r['price_amount'] : null,
                'per_person' => false, 'max_pax' => 0, 'image' => '',
                'legacy_featured' => $offered && guest_extra_bool($r['offer_at_booking'] ?? false),
                'legacy_hidden' => !($offered && guest_extra_bool($r['offer_at_booking'] ?? false)),
            ];
        }
    } catch (Throwable $e) { /* no transfers table yet */ }
    return $out;
}

/** The owner's saved choices for a property, keyed by extra key. [] = not curated. */
function guest_extras_settings(int $venueId): array {
    if (!guest_extras_supported()) return [];
    try {
        $rows = db_query('SELECT kind, ref_id, is_shown, is_featured, offer_when, sort_order FROM venue_extras WHERE venue_id = :v',
                         [':v' => $venueId])->fetchAll();
    } catch (Throwable $e) { return []; }
    $out = [];
    foreach ($rows as $r) $out[guest_extra_key((string)$r['kind'], (int)$r['ref_id'])] = [
        'shown' => guest_extra_bool($r['is_shown']), 'featured' => guest_extra_bool($r['is_featured']),
        'when' => (string)$r['offer_when'], 'sort' => (int)$r['sort_order'],
    ];
    return $out;
}

/**
 * Merge the catalogue with the owner's choices — PURE. Curated (any setting saved):
 * a catalogue item with no row is hidden (it was added after the owner's last save),
 * order = sort_order. Not curated: everything shown except transfers never offered
 * while booking, the old booking add-ons featured (first GUEST_EXTRAS_MAX_FEATURED),
 * catalogue order. Every row gets shown / featured / when / sort.
 */
function guest_extras_resolve(array $catalogue, array $settings): array {
    $curated = $settings !== [];
    $out = []; $i = 0; $featured = 0;
    foreach ($catalogue as $c) {
        $s = $settings[$c['key']] ?? null;
        if ($curated) {
            $c['shown']    = $s ? $s['shown'] : false;
            $c['featured'] = $s ? ($s['shown'] && $s['featured']) : false;
            $c['when']     = $s && isset(GUEST_EXTRAS_WHEN[$s['when']]) ? $s['when'] : 'any';
            $c['sort']     = $s ? $s['sort'] : 100000 + $i;
        } else {
            $c['shown']    = empty($c['legacy_hidden']);
            $c['featured'] = $c['shown'] && !empty($c['legacy_featured']) && $featured < GUEST_EXTRAS_MAX_FEATURED;
            if ($c['featured']) $featured++;
            $c['when']     = 'any';
            $c['sort']     = $i;
        }
        $out[] = $c; $i++;
    }
    usort($out, fn($a, $b) => [$a['sort'], $a['name']] <=> [$b['sort'], $b['name']]);
    return $out;
}

/** Is the stay still ahead ('before') or under way ('during')? PURE. */
function guest_extras_phase(array $hold, string $today): string {
    return $today < (string)$hold['check_in'] ? 'before' : 'during';
}

/** Keep the shown extras offered at this point of the stay. PURE. */
function guest_extras_for_phase(array $resolved, string $phase): array {
    return array_values(array_filter($resolved, fn($x) => $x['shown'] && ($x['when'] === 'any' || $x['when'] === $phase)));
}

/**
 * What the guest already asked for, per extra: the newest non-cancelled request's
 * status ('requested', 'confirmed', 'completed', 'declined'). Tours match on
 * tour_id; transfers on the option label the request starts with (that is what
 * api/booking-addon.php writes). PURE.
 * @return array<string,string> extra key => status
 */
function guest_extras_requested(array $resolved, array $addons): array {
    $out = [];
    foreach ($addons as $a) {   // fetch_booking_addons() is newest first — keep the first seen
        if (($a['status'] ?? '') === 'cancelled') continue;
        foreach ($resolved as $x) {
            if (isset($out[$x['key']])) continue;
            $hit = $x['kind'] === 'tour'
                ? ($a['kind'] ?? '') === 'tour' && (int)($a['tour_id'] ?? 0) === $x['ref_id']
                : ($a['kind'] ?? '') === 'transfer' && str_starts_with((string)($a['details'] ?? ''), $x['name']);
            if ($hit) $out[$x['key']] = (string)$a['status'];
        }
    }
    return $out;
}

/** Price label for an extra in the site currency ("USD 120 pp", "On request"). PURE apart from format_price(). */
function guest_extra_price_label(array $x): string {
    if ($x['price'] === null) return 'On request';
    return format_price((float)$x['price']) . ($x['per_person'] ? ' pp' : ($x['kind'] === 'transfer' ? ' per car' : ''));
}

/** The guest-facing list for one booking: phase-filtered, with request status. Never throws. */
function guest_extras_for_hold(array $hold, ?string $today = null): array {
    $venueId = isset($hold['venue_id']) && $hold['venue_id'] !== null ? (int)$hold['venue_id'] : null;
    if (!guest_extras_enabled($venueId)) return [];
    try {
        $resolved = guest_extras_resolve(guest_extras_catalogue($venueId), guest_extras_settings($venueId));
        $list = guest_extras_for_phase($resolved, guest_extras_phase($hold, $today ?? date('Y-m-d')));
        $req  = guest_extras_requested($list, fetch_booking_addons((int)$hold['id']));
        foreach ($list as $i => $x) { $list[$i]['status'] = $req[$x['key']] ?? ''; $list[$i]['price_label'] = guest_extra_price_label($x); }
        return $list;
    } catch (Throwable $e) {
        error_log('[guest-extras] list failed: ' . $e->getMessage());
        return [];
    }
}

/** The featured extras not yet asked for (home screen + emails). PURE. */
function guest_extras_featured(array $list, int $max = GUEST_EXTRAS_MAX_FEATURED): array {
    $f = array_values(array_filter($list, fn($x) => $x['featured'] && ($x['status'] ?? '') === ''));
    if (!$f) $f = array_values(array_filter($list, fn($x) => ($x['status'] ?? '') === ''));
    return array_slice($f, 0, $max);
}

/** The browser payload for the portal's add sheet. PURE. */
function guest_extra_payload(array $x): array {
    return ['k' => $x['key'], 'kind' => $x['kind'], 'id' => $x['ref_id'], 'slug' => $x['slug'], 'name' => $x['name'],
            'group' => $x['group'], 'cat' => $x['kind'] === 'transfer' ? 'transfer' : (preg_replace('/[^a-z]/', '', strtolower((string)$x['category'])) ?: 'any'),
            'dur' => $x['duration'], 'desc' => $x['desc'], 'price' => $x['price'],
            'pp' => $x['per_person'], 'max' => $x['max_pax'] > 0 ? $x['max_pax'] : 8, 'img' => $x['image'],
            'pl' => $x['price_label'] ?? guest_extra_price_label($x), 'status' => $x['status'] ?? ''];
}

/** Friendly status for a guest ("Waiting", "Confirmed"…) and its pill tone. PURE. */
function guest_extra_status_view(string $status): array {
    return match ($status) {
        'requested' => ['Waiting', 'pend'],
        'confirmed' => ['Confirmed', 'ok'],
        'completed' => ['Done', 'ok'],
        'declined'  => ['Not available', 'no'],
        'cancelled' => ['Cancelled', 'no'],
        default     => ['', ''],
    };
}

/** Property-level settings for the extras tab and the emails. */
function guest_extras_venue_options(int $venueId): array {
    $d = ['in_email' => true, 'reminder_days' => 3];
    if (!guest_extras_supported()) return $d;
    try {
        $r = db_query('SELECT extras_in_email, extras_reminder_days FROM venues WHERE id = :v', [':v' => $venueId])->fetch();
    } catch (Throwable $e) { return $d; }
    return $r ? ['in_email' => guest_extra_bool($r['extras_in_email']), 'reminder_days' => max(0, (int)$r['extras_reminder_days'])] : $d;
}

/**
 * Clean the admin form into rows — PURE. $posted: shown[key], featured[key],
 * when[key], order (comma list of keys, the drag order). Unknown keys are dropped;
 * a featured extra must be shown; at most GUEST_EXTRAS_MAX_FEATURED stay featured
 * (the first ones in order). Returns [rows, notice].
 */
function guest_extras_clean_post(array $catalogue, array $posted): array {
    $known = array_column($catalogue, null, 'key');
    $order = array_values(array_filter(array_map('trim', explode(',', (string)($posted['order'] ?? ''))), fn($k) => isset($known[$k])));
    foreach (array_keys($known) as $k) if (!in_array($k, $order, true)) $order[] = $k;
    $rows = []; $featured = 0; $dropped = 0;
    foreach ($order as $i => $k) {
        $shown = !empty($posted['shown'][$k]);
        $feat  = $shown && !empty($posted['featured'][$k]);
        if ($feat && $featured >= GUEST_EXTRAS_MAX_FEATURED) { $feat = false; $dropped++; }
        if ($feat) $featured++;
        $when = (string)($posted['when'][$k] ?? 'any');
        $rows[] = ['kind' => $known[$k]['kind'], 'ref_id' => $known[$k]['ref_id'], 'shown' => $shown, 'featured' => $feat,
                   'when' => isset(GUEST_EXTRAS_WHEN[$when]) ? $when : 'any', 'sort' => $i];
    }
    $notice = $dropped ? 'Only ' . GUEST_EXTRAS_MAX_FEATURED . ' extras can be featured — the first ' . GUEST_EXTRAS_MAX_FEATURED . ' in the list were kept.' : '';
    return [$rows, $notice];
}

/** Save a property's extras + options in one transaction. Owner pages only (the caller checks). */
function guest_extras_save(int $venueId, array $rows, bool $inEmail, int $reminderDays): void {
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        db_query('DELETE FROM venue_extras WHERE venue_id = :v', [':v' => $venueId]);
        foreach ($rows as $r) {
            db_query('INSERT INTO venue_extras (venue_id, kind, ref_id, is_shown, is_featured, offer_when, sort_order)
                      VALUES (:v, :k, :r, :s, :f, :w, :o)',
                [':v' => $venueId, ':k' => $r['kind'], ':r' => $r['ref_id'], ':s' => $r['shown'] ? 'TRUE' : 'FALSE',
                 ':f' => $r['featured'] ? 'TRUE' : 'FALSE', ':w' => $r['when'], ':o' => $r['sort']]);
        }
        db_query('UPDATE venues SET extras_in_email = :e, extras_reminder_days = :d WHERE id = :v',
                 [':e' => $inEmail ? 'TRUE' : 'FALSE', ':d' => max(0, min(30, $reminderDays)), ':v' => $venueId]);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Requests for this property's extras in the last $days (by request date):
 * asked for, confirmed/done, and the confirmed value in the site currency. Never throws.
 */
function guest_extras_stats(int $venueId, int $days = 30): array {
    $z = ['requested' => 0, 'confirmed' => 0, 'value' => 0.0];
    try {
        $r = db_query(
            "SELECT COUNT(*) AS requested,
                    COUNT(*) FILTER (WHERE ba.status IN ('confirmed','completed')) AS confirmed,
                    COALESCE(SUM(ba.price_amount) FILTER (WHERE ba.status IN ('confirmed','completed')), 0) AS value
               FROM booking_addons ba
               JOIN holds h ON h.id = ba.hold_id
               JOIN units u ON u.id = h.unit_id
               JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u') . "
              WHERE r.venue_id = :v AND ba.kind IN ('tour','transfer')
                AND ba.created_at > NOW() - (:d || ' days')::interval",
            [':v' => $venueId, ':d' => (string)$days])->fetch();
    } catch (Throwable $e) { return $z; }
    return $r ? ['requested' => (int)$r['requested'], 'confirmed' => (int)$r['confirmed'], 'value' => (float)$r['value']] : $z;
}

/**
 * Up to $max extras for an email about $holdId: the featured ones the guest hasn't
 * asked for, as [name, price label, link into the portal's Extras]. [] when the
 * property's emails carry no extras. Never throws.
 */
function guest_extras_email_rows(int $holdId, ?int $venueId, string $manageUrl, int $max = GUEST_EXTRAS_MAX_FEATURED): array {
    if (!$venueId || $manageUrl === '') return [];
    if (!guest_extras_venue_options($venueId)['in_email']) return [];
    try {
        $hold = fetch_hold_for_guest($holdId);
        if (!$hold) return [];
        $hold['venue_id'] = $hold['venue_id'] ?? $venueId;
        return array_map(fn($x) => [$x['name'], $x['price_label'], $manageUrl . '&view=extras&extra=' . rawurlencode($x['key'])],
                         guest_extras_featured(guest_extras_for_hold($hold), $max));
    } catch (Throwable $e) { return []; }
}

/**
 * Bookings whose "add to your stay" reminder is due today: confirmed, arriving in
 * exactly the property's reminder_days (Nairobi date), not reminded yet. Never throws.
 * @return list<int> hold ids
 */
function guest_extras_reminders_due(string $today): array {
    if (!guest_extras_supported()) return [];
    $switch = upsells_supported() ? ' AND v.upsell_enabled = TRUE' : '';
    try {
        return array_map('intval', db_query(
            "SELECT h.id FROM holds h
               JOIN units u ON u.id = h.unit_id
               JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u') . "
               JOIN venues v ON v.id = r.venue_id
              WHERE h.status = 'confirmed' AND h.extras_reminder_sent_at IS NULL
                AND v.extras_reminder_days > 0{$switch}
                AND h.check_in = (:t::date + v.extras_reminder_days)
              ORDER BY h.id",
            [':t' => $today])->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        error_log('[guest-extras] reminders query failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Claim a booking's reminder: true for exactly one caller, even when several app
 * containers run the job at once (the UPDATE only matches an unclaimed row). The
 * claim stands whether the email then sends, is switched off or has nothing to
 * offer, so a booking is never reminded twice.
 */
function guest_extras_claim_reminder(int $holdId): bool {
    try {
        return (bool)db_query('UPDATE holds SET extras_reminder_sent_at = NOW() WHERE id = :h AND extras_reminder_sent_at IS NULL RETURNING id',
                              [':h' => $holdId])->fetchColumn();
    } catch (Throwable $e) {
        error_log('[guest-extras] claim reminder failed: ' . $e->getMessage());
        return false;
    }
}
