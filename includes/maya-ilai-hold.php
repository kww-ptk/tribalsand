<?php
declare(strict_types=1);
/**
 * Atomic multi-room Maya Ilai booking (Phase B).
 *
 * A configurator offer is several products over the shared villa pool. This turns
 * one into a real, all-or-nothing set of 24h holds: the submission and every hold
 * are written in ONE transaction, so either the whole stay is held or nothing is
 * (a half-booked party is worse than a clean "those dates just went").
 *
 * Correctness rests on three things already proven elsewhere and reused here, not
 * re-implemented:
 *   · Allocation + overbooking safety — mi_allocate_and_hold() (villa products,
 *     advisory-locked) and find_available_unit()+create_hold_with_block()
 *     (studios), the same calls the single-room enquiry path uses. Run inside our
 *     outer transaction they see each other's uncommitted blocks, so two studios
 *     never land on the same unit.
 *   · Price — the SAME maya_ilai_quote() the guest was shown (re-computed here on
 *     the live band; the client's number is never trusted). Its total is split
 *     across the holds by mi_proportional_shares() and frozen on each via
 *     holds.quoted_amount, which bookings_sync_hold() reads at confirm time — so
 *     guest, staff and ledger all read one number.
 *   · Availability truth — mi_can_place_products() re-checks the live calendar
 *     before we start writing.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/maya-ilai-pricing.php';

/**
 * Book a whole configuration atomically. $units is the offer's product list:
 * [ ['key'=>string, 'qty'=>int, 'guests'=>int], … ]. Returns
 *   ['ok'=>true,'submission_id'=>int,'holds'=>[['id','access_code','room_name'],…],
 *    'total'=>float,'currency'=>string,'rooms'=>string,'nights'=>int]
 * or ['ok'=>false,'error'=>string,'code'=>int] (409 = taken, 422 = bad request).
 *
 * Never sends email — the caller does that after the commit.
 */
function mi_book_configuration(
    array $units, string $ci, string $co,
    string $guestName, string $guestEmail, string $guestPhone = '',
    string $message = '', array $tracking = []
): array {
    $cfg = maya_ilai_pricing_get();
    $err = fn(string $m, int $c = 422): array => ['ok' => false, 'error' => $m, 'code' => $c];

    $ciN = rates_window_ymd($ci);
    $coN = rates_window_ymd($co);
    if ($ciN === null || $coN === null || $ciN >= $coN) return $err('Please choose valid dates.');
    $nights = max(1, (int)((strtotime($coN) - strtotime($ciN)) / 86400));

    // Rebuild picks from the posted product list, validated against the catalogue.
    $byKey = [];
    foreach (maya_ilai_products($cfg) as $p) $byKey[$p['key']] = $p;
    $picks = []; $alloc = []; $totalGuests = 0;
    foreach ($units as $u) {
        $key = (string)($u['key'] ?? '');
        $qty = max(0, (int)($u['qty'] ?? 0));
        if (!isset($byKey[$key]) || $qty < 1) return $err('That selection is not available to book online.');
        $p = $byKey[$key];
        $g = (int)($u['guests'] ?? 0);
        $g = max((int)$p['min'] * $qty, min((int)$p['max'] * $qty, $g));  // clamp into the product's occupancy
        $picks[] = ['product' => $p, 'qty' => $qty];
        $alloc[] = $g;
        $totalGuests += $g;
    }
    if (!$picks || $totalGuests < 1) return $err('Add at least one room and guest.');

    // Live availability + the guest price, recomputed server-side (never trust the
    // client). Composite layer live → price on the availability band; otherwise the
    // group program (the pre-band behaviour), so a pre-migration DB still books.
    $live = mi_live_availability($ciN, $coN);
    $sel  = maya_ilai_picks_to_sel($picks, $alloc, $nights, $cfg);
    if (!empty($live['supported'])) {
        $sel['program']        = 'live';
        $sel['availableUnits'] = (int)$live['freeVillas'];
    }
    $quote = maya_ilai_quote($sel, $cfg);
    if (!empty($quote['errors']))       return $err('That configuration can’t be booked: ' . implode(' ', $quote['errors']));
    if (!empty($quote['sold']))         return $err('We’re fully booked for those dates.', 409);
    if ((int)$quote['guests'] !== $totalGuests) return $err('Guest counts didn’t add up — please try again.');

    // Re-check the real calendar before writing anything.
    if (!empty($live['supported'])) {
        $demand = maya_ilai_offer_demand($picks);
        if (!mi_can_place_products($live['villaStates'] ?? [], $demand['villaSlugs'],
                $demand['studios'], (int)($live['freeStudios'] ?? 0), (int)($live['reserved'] ?? 0))) {
            return $err('Those rooms just became unavailable for these dates.', 409);
        }
    }

    $currency = (string)($quote['currency'] ?? 'USD');
    $shares   = mi_proportional_shares($picks, (float)$quote['total'], $cfg);
    $roomsLabel = maya_ilai_picks_label($picks);
    $writeQuoted = holds_quoted_amount_supported();

    $pdo = db();
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();
    try {
        // One submission the whole party hangs off. room_id is NULL — the stay is
        // several rooms, not one — which submission_in_scope() treats as visible to
        // owner/managers, exactly like a contact enquiry.
        db_query(
            "INSERT INTO submissions
                (type, room_id, guest_name, guest_email, guest_phone, message,
                 check_in, check_out, guests_adults, guests_children, payload_json,
                 source_page, referrer, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                 user_agent, ip_address)
             VALUES
                ('enquiry', NULL, :name, :email, :phone, :message,
                 :ci, :co, :adults, 0, :payload,
                 :source_page, :referrer, :utm_source, :utm_medium, :utm_campaign, :utm_term, :utm_content,
                 :ua, :ip)",
            [
                ':name' => $guestName, ':email' => $guestEmail, ':phone' => $guestPhone,
                ':message' => trim("Maya Ilai booking: {$roomsLabel}\n{$message}"),
                ':ci' => $ciN, ':co' => $coN, ':adults' => $totalGuests,
                ':payload' => json_encode([
                    'maya_ilai_booking' => true,
                    'rooms'             => $roomsLabel,
                    'quoted_total'      => round((float)$quote['total'], 2),
                    'quoted_currency'   => $currency,
                    'quoted_label'      => $nights . ' night' . ($nights === 1 ? '' : 's') . ' · as shown to the guest',
                ], JSON_UNESCAPED_SLASHES),
                ':source_page' => $tracking['source_page'] ?? '',
                ':referrer'    => $tracking['referrer']    ?? '',
                ':utm_source'  => $tracking['utm_source']  ?? '',
                ':utm_medium'  => $tracking['utm_medium']  ?? '',
                ':utm_campaign'=> $tracking['utm_campaign']?? '',
                ':utm_term'    => $tracking['utm_term']    ?? '',
                ':utm_content' => $tracking['utm_content'] ?? '',
                ':ua'          => $tracking['user_agent']  ?? '',
                ':ip'          => $tracking['ip'] ?? client_ip(),
            ]
        );
        $subId = (int)$pdo->lastInsertId();

        $roomCache = [];
        $roomBySlug = function (string $slug) use (&$roomCache) {
            if (!array_key_exists($slug, $roomCache)) {
                $roomCache[$slug] = db_query('SELECT * FROM rooms WHERE slug = :s', [':s' => $slug])->fetch() ?: null;
            }
            return $roomCache[$slug];
        };

        $slugMap = maya_ilai_room_slugs();
        $holds   = [];
        foreach ($picks as $i => $pick) {
            $key  = (string)$pick['product']['key'];
            $qty  = (int)$pick['qty'];
            $slug = $slugMap[$key] ?? null;
            if ($slug === null) throw new RuntimeException('unbookable product ' . $key);
            $room = $roomBySlug($slug);
            if (!$room) throw new RuntimeException('missing room ' . $slug);

            // The pick's share, split evenly across its identical units (remainder
            // to the first) so the per-hold amounts still sum to the pick's share.
            $perUnit = $qty > 0 ? round((float)$shares[$i] / $qty, 2) : 0.0;
            $unitShares = array_fill(0, $qty, $perUnit);
            $drift = round((float)$shares[$i] - array_sum($unitShares), 2);
            if ($qty > 0 && abs($drift) >= 0.01) $unitShares[0] = round($unitShares[0] + $drift, 2);

            for ($k = 0; $k < $qty; $k++) {
                if ($slug === 'maya-ilai-studio') {
                    $unit = find_available_unit((int)$room['id'], $ciN, $coN);
                    if (!$unit) throw new MiSoldOutException();
                    $holdId = create_hold_with_block((int)$unit['id'], $subId, $ciN, $coN,
                        $guestName, $guestEmail, 'pending', 24, null, (int)$room['id']);
                } else {
                    $holdId = mi_allocate_and_hold($room, $subId, $ciN, $coN,
                        $guestName, $guestEmail, 'pending', 24);
                }
                if ($holdId === false) throw new MiSoldOutException();

                if ($writeQuoted) {
                    db_query('UPDATE holds SET quoted_amount = :a, quoted_currency = :c WHERE id = :id',
                        [':a' => $unitShares[$k], ':c' => $currency, ':id' => $holdId]);
                }
                $holds[] = ['id' => (int)$holdId, 'room_name' => (string)$room['name']];
            }
        }

        // Access codes for the confirmation, read inside the transaction.
        foreach ($holds as $idx => $h) {
            $holds[$idx]['access_code'] = (string) db_query(
                'SELECT access_code FROM holds WHERE id = :id', [':id' => $h['id']]
            )->fetchColumn();
        }

        if ($ownTx) $pdo->commit();
        return [
            'ok' => true, 'submission_id' => $subId, 'holds' => $holds,
            'total' => round((float)$quote['total'], 2), 'currency' => $currency,
            'rooms' => $roomsLabel, 'nights' => $nights,
        ];
    } catch (MiSoldOutException $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        return $err('Those rooms just became unavailable for these dates. Please try different dates.', 409);
    } catch (\Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[mi_book_configuration] ' . $e->getMessage());
        return $err('Something went wrong holding your rooms. Please try again or contact us.', 500);
    }
}

/** Raised when an allocation loses the race inside the booking transaction. */
class MiSoldOutException extends \RuntimeException {}
