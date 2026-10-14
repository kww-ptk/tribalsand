<?php
declare(strict_types=1);
/**
 * Gate pass + gate arrivals (Oct 2026).
 *
 * Once a guest has done online check-in, their booking page shows a GATE PASS:
 * a QR code (plus the booking code) to show at the gate. The QR holds a link to
 * the Gate page with a signed pass token — security scan it (Gate → "Scan gate
 * pass", or any phone camera), see the party and where they are going, and
 * check them in. Arrival/departure is stored on the hold (add_gate_arrivals.sql).
 *
 * The token only NAMES the booking; it grants nothing on its own — the Gate page
 * needs a signed-in gate account (require_gate()) scoped to the property.
 * Pure helpers first (tested by tests/gate_pass_logic.php), then the I/O.
 */
require_once __DIR__ . '/db.php';

const GATE_PASS_PREFIX = 'GP';

/** True once add_gate_arrivals.sql has run. Catalog lookup — safe inside a transaction. */
function gate_arrivals_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try {
        return $c = (bool) db_query(
            "SELECT 1 FROM information_schema.columns
              WHERE table_schema = current_schema() AND table_name = 'holds' AND column_name = 'gate_arrived_at'"
        )->fetchColumn();
    } catch (Throwable $e) { return $c = false; }
}

/** The pass token for a hold: "GP-<id>-<hmac12>". '' when BOOKING_TOKEN_SECRET is unset. */
function gate_pass_token(int $holdId, ?string $secret = null): string {
    $secret ??= (string)(parse_env()['BOOKING_TOKEN_SECRET'] ?? '');
    if ($secret === '' || $holdId <= 0) return '';
    return GATE_PASS_PREFIX . '-' . $holdId . '-' . substr(hash_hmac('sha256', "gate:{$holdId}", $secret), 0, 12);
}

/** Hold id from a pass token, or false. Case-insensitive (a typed code may be upper-cased). */
function gate_pass_verify(string $tok, ?string $secret = null): int|false {
    $secret ??= (string)(parse_env()['BOOKING_TOKEN_SECRET'] ?? '');
    if ($secret === '') return false;
    if (!preg_match('/^GP-(\d{1,10})-([0-9a-f]{12})$/i', trim($tok), $m)) return false;
    $id = (int)$m[1];
    return hash_equals(gate_pass_token($id, $secret), GATE_PASS_PREFIX . '-' . $id . '-' . strtolower($m[2])) ? $id : false;
}

/**
 * The pass token inside whatever a scanner read: the whole gate link
 * (https://…/admin/gate.php?pass=GP-…) or the bare token. '' when none.
 */
function gate_pass_from_scan(string $text): string {
    return preg_match('/\bGP-\d{1,10}-[0-9a-fA-F]{12}\b/', $text, $m) ? $m[0] : '';
}

/** The link the QR code carries. */
function gate_pass_url(int $holdId): string {
    $t = gate_pass_token($holdId);
    return $t === '' ? '' : site_url('/admin/gate.php?pass=' . rawurlencode($t));
}

/**
 * May the guest see their gate pass yet? Pure.
 * A booking (confirmed, or staff-typed pending = no expiry) whose stay has not
 * ended, and — when check-in is required — the lead has finished their part.
 * Returns ['ok' => bool, 'why' => guest-facing reason when not ok].
 */
function gate_pass_ready(string $status, bool $hasExpiry, bool $checkinRequired, bool $checkinDone, string $checkOut, string $today): array {
    $isBooking = $status === 'confirmed' || ($status === 'pending' && !$hasExpiry);
    if (!$isBooking) {
        return ['ok' => false, 'why' => $status === 'pending'
            ? 'Your gate pass appears here once your booking is confirmed.'
            : 'This booking has no gate pass.'];
    }
    if ($checkOut !== '' && $checkOut < $today) return ['ok' => false, 'why' => 'Your stay has ended.'];
    if ($checkinRequired && !$checkinDone) {
        return ['ok' => false, 'why' => 'Finish online check-in to get your gate pass.'];
    }
    return ['ok' => true, 'why' => ''];
}

/**
 * Who is coming. Pure. $guests = checkin_guests rows (passport_name, is_child),
 * $bookedParty = the booking's own party size (guest_count / the request's
 * adults+children). The total is the larger of the roster and the booking, so
 * a party whose co-guests have not added themselves still shows its full size.
 */
function gate_party(array $guests, int $bookedParty, string $leadName = ''): array {
    $names = []; $adults = 0; $children = 0;
    foreach ($guests as $g) {
        $child = !empty($g['is_child']) && $g['is_child'] !== 'f';
        $child ? $children++ : $adults++;
        $n = trim((string)($g['passport_name'] ?? ''));
        if ($n !== '') $names[] = ['name' => $n, 'child' => $child];
    }
    if (!$names && trim($leadName) !== '') { $names[] = ['name' => trim($leadName), 'child' => false]; $adults = max($adults, 1); }
    $total = max($adults + $children, $bookedParty, 1);
    return ['total' => $total, 'adults' => $adults, 'children' => $children,
            'unnamed' => max(0, $total - count($names)), 'names' => $names];
}

/** "4 people · 3 adults, 1 child" — the short line on the pass and the Gate. Pure. */
function gate_party_label(array $party): string {
    $t = (int)$party['total'];
    $s = $t . ' ' . ($t === 1 ? 'person' : 'people');
    $bits = [];
    if ((int)$party['adults'] > 0)   $bits[] = (int)$party['adults'] . ' adult' . ((int)$party['adults'] === 1 ? '' : 's');
    if ((int)$party['children'] > 0) $bits[] = (int)$party['children'] . ' ' . ((int)$party['children'] === 1 ? 'child' : 'children');
    return $bits && ((int)$party['adults'] + (int)$party['children']) === $t ? $s . ' · ' . implode(', ', $bits) : $s;
}

/**
 * What the guard should do with a scanned booking. Pure.
 * 'stop' = do not admit (cancelled, stay over, another property);
 * 'warn' = check before admitting (early, check-in unfinished, already in);
 * 'ok'   = expected today or in house.
 */
function gate_verdict(array $hold, string $today, bool $inScope): array {
    $status = (string)($hold['status'] ?? '');
    $ci = (string)($hold['check_in'] ?? ''); $co = (string)($hold['check_out'] ?? '');
    if (in_array($status, ['cancelled', 'expired'], true)) return ['level' => 'stop', 'msg' => 'This booking is ' . $status . '. Do not admit — call reception.'];
    if ($status === 'pending' && !empty($hold['expires_at'])) return ['level' => 'stop', 'msg' => 'This booking is not confirmed yet. Call reception.'];
    if (!$inScope) return ['level' => 'stop', 'msg' => 'This pass is for ' . ((string)($hold['venue_name'] ?? '') ?: 'another property') . ', which you don’t cover.'];
    if ($co !== '' && $co < $today) return ['level' => 'stop', 'msg' => 'This stay ended on ' . date('j M', strtotime($co)) . '.'];
    if ($ci !== '' && $ci > $today) return ['level' => 'warn', 'msg' => 'They are booked from ' . date('D j M', strtotime($ci)) . ' — not today. Check with reception.'];
    if (!empty($hold['gate_departed_at'])) return ['level' => 'warn', 'msg' => 'Already checked out at ' . date('j M H:i', strtotime((string)$hold['gate_departed_at'])) . '.'];
    if (!empty($hold['gate_arrived_at'])) return ['level' => 'ok', 'msg' => 'In house — checked in at ' . date('j M H:i', strtotime((string)$hold['gate_arrived_at'])) . '.'];
    if (!empty($hold['require_checkin']) && empty($hold['checkin_completed_at']) && empty($hold['checkin_submitted_at'])) {
        return ['level' => 'warn', 'msg' => 'Online check-in is not finished. Send them to reception.'];
    }
    return ['level' => 'ok', 'msg' => $co === $today ? 'Leaving today.' : ($ci === $today ? 'Expected today.' : 'Staying with us.')];
}

// ── I/O ─────────────────────────────────────────────────────────────

/**
 * Everything the pass and the Gate show for one booking, or null.
 * Adds: party (gate_party()), checkin_submitted_at, unit_name, venue_name.
 */
function gate_pass_booking(int $holdId): ?array {
    require_once __DIR__ . '/booking.php';
    require_once __DIR__ . '/checkin.php';
    $h = fetch_hold_for_guest($holdId);
    if (!$h) return null;
    $booked = max(0, (int)($h['guest_count'] ?? 0));
    if (!empty($h['submission_id'])) {
        try {
            $booked = max($booked, (int)db_query('SELECT COALESCE(guests_adults,0) + COALESCE(guests_children,0) FROM submissions WHERE id = :s',
                [':s' => (int)$h['submission_id']])->fetchColumn());
        } catch (Throwable $e) {}
    }
    $guests = fetch_checkin_guests($holdId);
    $ci = fetch_checkin($holdId);
    $h['checkin_submitted_at'] = $ci['submitted_at'] ?? null;
    $h['party'] = gate_party($guests, $booked, (string)($h['guest_name'] ?? ''));
    $h['party_done'] = count(array_filter($guests, fn($g) => !empty($g['waiver_signed_at'])));
    return $h;
}

/** Is the guest's pass ready (DB wrapper over gate_pass_ready()). */
function gate_pass_ready_for(array $hold): array {
    require_once __DIR__ . '/checkin.php';
    $required = checkin_required($hold);
    $done = checkin_is_complete($hold);
    if ($required && !$done) { $ci = fetch_checkin((int)$hold['id']); $done = !empty($ci['submitted_at']); }
    $today = (new DateTime('now', new DateTimeZone('Africa/Nairobi')))->format('Y-m-d');
    return gate_pass_ready((string)$hold['status'], !empty($hold['expires_at']), $required, $done, (string)$hold['check_out'], $today);
}

/** Check a party in at the gate. False when already in (or the migration has not run). */
function gate_mark_arrived(int $holdId, int $byAdminId, int $people): bool {
    if (!gate_arrivals_supported()) return false;
    $n = db_query(
        "UPDATE holds SET gate_arrived_at = NOW(), gate_arrived_by = :b, gate_people = :p,
                          gate_departed_at = NULL, gate_departed_by = NULL
          WHERE id = :h AND gate_arrived_at IS NULL",
        [':h' => $holdId, ':b' => $byAdminId ?: null, ':p' => max(1, min(99, $people))]
    )->rowCount();
    if ($n && function_exists('audit_log')) audit_log('gate.arrived', 'hold', $holdId, $people . ' people');
    return $n > 0;
}

/** Check a party out at the gate (they leave). */
function gate_mark_departed(int $holdId, int $byAdminId): bool {
    if (!gate_arrivals_supported()) return false;
    $n = db_query(
        "UPDATE holds SET gate_departed_at = NOW(), gate_departed_by = :b,
                          gate_arrived_at = COALESCE(gate_arrived_at, NOW())
          WHERE id = :h AND gate_departed_at IS NULL",
        [':h' => $holdId, ':b' => $byAdminId ?: null]
    )->rowCount();
    if ($n && function_exists('audit_log')) audit_log('gate.departed', 'hold', $holdId, '');
    return $n > 0;
}

/** Undo a check-in made by mistake (clears arrival and departure). */
function gate_undo(int $holdId): bool {
    if (!gate_arrivals_supported()) return false;
    $n = db_query(
        "UPDATE holds SET gate_arrived_at = NULL, gate_arrived_by = NULL, gate_people = NULL,
                          gate_departed_at = NULL, gate_departed_by = NULL
          WHERE id = :h AND gate_arrived_at IS NOT NULL",
        [':h' => $holdId]
    )->rowCount();
    if ($n && function_exists('audit_log')) audit_log('gate.undo', 'hold', $holdId, '');
    return $n > 0;
}

/** A booking for the gate's "type the code" box: the booking code (access_code) within scope, staying today-ish. */
function gate_find_by_code(string $code, ?array $venueIds, string $today): ?int {
    $code = strtoupper(trim($code));
    if ($code === '') return null;
    if (($id = gate_pass_verify($code)) !== false) return $id;
    $p = [':c' => $code, ':d' => $today];
    $scope = '';
    if ($venueIds !== null) {
        $ids = $venueIds ?: [-1]; $ph = [];
        foreach ($ids as $i => $v) { $ph[] = ":gv{$i}"; $p[":gv{$i}"] = (int)$v; }
        $scope = ' AND r.venue_id IN (' . implode(',', $ph) . ')';
    }
    try {
        $id = db_query(
            "SELECT h.id FROM holds h
               JOIN units u ON u.id = h.unit_id
               JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u') . "
              WHERE UPPER(h.access_code) = :c AND h.check_out >= :d{$scope}
              ORDER BY h.check_in LIMIT 1", $p)->fetchColumn();
        return $id ? (int)$id : null;
    } catch (Throwable $e) { return null; }
}
