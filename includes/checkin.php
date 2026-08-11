<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';   // is_owner(), is_manager()
require_once __DIR__ . '/team.php';   // staff_can_hold()

/** Fixed step catalog. Array order = wizard order. */
function checkin_step_catalog(): array {
    return [
        'arrival'  => ['label' => 'Arrival & flight',     'default_required' => false],
        'transfer' => ['label' => 'Airport transfer',     'default_required' => false],
        'passport' => ['label' => 'Passport & identity',  'default_required' => true],
        'dietary'  => ['label' => 'Dietary requirements', 'default_required' => false],
        'requests' => ['label' => 'Special requests',     'default_required' => false],
        'waiver'   => ['label' => 'Waiver & indemnity',   'default_required' => true],
    ];
}

/** Merged config: catalog defaults overlaid with the `checkin_steps` setting (JSON). */
function checkin_config(): array {
    $overrides = [];
    $raw = setting('checkin_steps', '');
    if ($raw !== '') { $d = json_decode($raw, true); if (is_array($d)) $overrides = $d; }
    $out = [];
    foreach (checkin_step_catalog() as $key => $def) {
        $o = is_array($overrides[$key] ?? null) ? $overrides[$key] : [];
        $out[$key] = [
            'label'    => $def['label'],
            'enabled'  => array_key_exists('enabled', $o)  ? (bool)$o['enabled']  : true,
            'required' => array_key_exists('required', $o) ? (bool)$o['required'] : $def['default_required'],
        ];
    }
    return $out;
}

/** Enabled steps only, order preserved. */
function checkin_enabled_steps(): array {
    return array_filter(checkin_config(), fn($s) => $s['enabled']);
}

/** True once the migration is applied. Cached per-request. */
function checkin_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db_query('SELECT require_checkin, guest_count FROM holds LIMIT 1'); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

function checkin_required(array $hold): bool {
    return checkin_supported() && !empty($hold['require_checkin']);
}

function checkin_is_complete(array $hold): bool {
    return !empty($hold['checkin_completed_at']);
}

/** 'none' (not required) | 'pending' (required, unfinished) | 'complete'. */
function checkin_state(array $hold): string {
    if (!checkin_required($hold)) return 'none';
    return checkin_is_complete($hold) ? 'complete' : 'pending';
}

/**
 * Badge descriptor for Frontdesk/Holds, or null when not required. When the caller
 * supplies `guest_count` (>1) and `ci_complete_count`, renders "X/N" for a party.
 */
function checkin_badge(array $hold): ?array {
    $state = checkin_state($hold);
    if ($state === 'none')     return null;
    if ($state === 'complete') return ['label' => 'Checked in ✓', 'class' => 'ci-badge--done'];
    $n = (int)($hold['guest_count'] ?? 1);
    if ($n > 1 && array_key_exists('ci_complete_count', $hold)) {
        $x = max(0, min((int)$hold['ci_complete_count'], $n));
        return ['label' => "Check-in {$x}/{$n}", 'class' => 'ci-badge--pending'];
    }
    return ['label' => 'Check-in pending', 'class' => 'ci-badge--pending'];
}

function fetch_checkin(int $holdId): ?array {
    if (!checkin_supported()) return null;
    try { $r = db_query('SELECT * FROM booking_checkin WHERE hold_id = :h', [':h' => $holdId])->fetch(); }
    catch (Throwable $e) { return null; }
    return $r ?: null;
}

function fetch_checkin_guests(int $holdId): array {
    if (!checkin_supported()) return [];
    try { return db_query('SELECT * FROM checkin_guests WHERE hold_id = :h ORDER BY is_lead DESC, id', [':h' => $holdId])->fetchAll(); }
    catch (Throwable $e) { return []; }
}

function checkin_lead_guest(int $holdId): ?array {
    foreach (fetch_checkin_guests($holdId) as $g) { if (!empty($g['is_lead'])) return $g; }
    return null;
}

/** 12-char stable label for the waiver terms the guest agreed to. */
function waiver_version(string $text): string {
    return substr(sha1(trim($text)), 0, 12);
}

/** Owner, or a manager who manages this booking's venue, may view passport docs. */
function can_view_guest_docs(int $holdId): bool {
    if (is_owner()) return true;
    return is_manager() && staff_can_hold($holdId);
}

/**
 * Is one step's *required* data present? $data = booking_checkin row (or null),
 * $lead = lead checkin_guests row (or null).
 */
function checkin_step_complete(string $key, ?array $data, ?array $lead): bool {
    $data = $data ?? [];
    $has = fn($k) => trim((string)($data[$k] ?? '')) !== '';
    switch ($key) {
        case 'arrival':  return $has('flight_number') && !empty($data['arrival_at']);
        case 'transfer':
            if (!array_key_exists('needs_transfer', $data) || $data['needs_transfer'] === null) return false;
            $wants = ($data['needs_transfer'] === true || $data['needs_transfer'] === 't' || $data['needs_transfer'] === '1' || $data['needs_transfer'] === 1);
            return $wants ? trim((string)($data['transfer_details'] ?? '')) !== '' : true;
        case 'passport': return checkin_guest_passport_complete($lead);
        case 'dietary':  return $has('dietary');
        case 'requests': return $has('special_requests');
        case 'waiver':   return checkin_guest_waiver_signed($lead);   // per-guest (moved off booking_checkin)
        default:         return false;
    }
}

/** Enabled+required steps that are still incomplete. Empty array = ready to submit. */
function checkin_missing_steps(array $config, ?array $data, ?array $lead): array {
    $missing = [];
    foreach ($config as $key => $s) {
        if (empty($s['enabled']) || empty($s['required'])) continue;
        if (!checkin_step_complete($key, $data, $lead)) $missing[] = $key;
    }
    return $missing;
}

// ── Multi-guest per booking ─────────────────────────────────────────────────

/** A single guest row has a complete passport (name + number + scan). */
function checkin_guest_passport_complete(?array $g): bool {
    return $g !== null
        && trim((string)($g['passport_name'] ?? '')) !== ''
        && trim((string)($g['passport_number'] ?? '')) !== ''
        && trim((string)($g['passport_file_key'] ?? '')) !== '';
}

/** A single guest row has signed the waiver (name + timestamp). */
function checkin_guest_waiver_signed(?array $g): bool {
    return $g !== null
        && !empty($g['waiver_signed_at'])
        && trim((string)($g['waiver_signed_name'] ?? '')) !== '';
}

/** True if $s is a PNG data-URL within the size cap (a drawn signature). Pure. */
function checkin_valid_signature(string $s): bool {
    $prefix = 'data:image/png;base64,';
    if (strncmp($s, $prefix, strlen($prefix)) !== 0) return false;
    $bin = base64_decode(substr($s, strlen($prefix)), true);
    if ($bin === false) return false;
    $len = strlen($bin);
    if ($len < 8 || $len > 250 * 1024) return false;         // too small / too large
    return strncmp($bin, "\x89PNG\r\n\x1a\n", 8) === 0;       // PNG magic bytes
}

/** Is an ADULT guest fully done — passport (if that step is required) AND waiver (if required). */
function checkin_guest_complete(?array $g, array $config): bool {
    if ($g === null || !empty($g['is_child'])) return false;
    $needPass   = !empty($config['passport']['enabled']) && !empty($config['passport']['required']);
    $needWaiver = !empty($config['waiver']['enabled'])   && !empty($config['waiver']['required']);
    if ($needPass   && !checkin_guest_passport_complete($g)) return false;
    if ($needWaiver && !checkin_guest_waiver_signed($g))     return false;
    return true;
}

/** Pure: clamp completed vs required party size. */
function checkin_party_status(int $adultCount, int $completeCount): array {
    $n = max(1, $adultCount);
    $x = max(0, min($completeCount, $n));
    return ['complete' => $x, 'total' => $n, 'all_done' => ($x >= $n)];
}

/** Count ADULT guest rows (is_child = false) that are fully complete. */
function checkin_party_complete_count(array $guests, array $config): int {
    $c = 0;
    foreach ($guests as $g) { if (empty($g['is_child']) && checkin_guest_complete($g, $config)) $c++; }
    return $c;
}

/**
 * Recompute booking completion after any per-guest write. Stamps
 * holds.checkin_completed_at (+ audit + best-effort staff email) EXACTLY ONCE, when
 * the lead's booking-level required steps are done AND all N adults are complete.
 * Safe to call from every write path. Returns true if fully checked in.
 * Requires includes/booking.php (fetch_hold_for_guest) — always loaded by callers.
 */
function checkin_recompute_completion(int $holdId): bool {
    if (!checkin_supported()) return false;
    require_once __DIR__ . '/mail.php';
    $hold = fetch_hold_for_guest($holdId);
    if (!$hold || !checkin_required($hold)) return false;
    $config = checkin_config();
    $data   = fetch_checkin($holdId);
    $lead   = checkin_lead_guest($holdId);
    // Booking-level required steps only (passport/waiver are covered per-guest below).
    foreach ($config as $key => $s) {
        if ($key === 'passport' || $key === 'waiver') continue;
        if (empty($s['enabled']) || empty($s['required'])) continue;
        if (!checkin_step_complete($key, $data, $lead)) return false;
    }
    $need = max(1, (int)($hold['guest_count'] ?? 1));
    if (checkin_party_complete_count(fetch_checkin_guests($holdId), $config) < $need) return false;

    $stmt = db_query("UPDATE holds SET checkin_completed_at = now() WHERE id = :h AND checkin_completed_at IS NULL", [':h' => $holdId]);
    if ($stmt->rowCount() > 0) {   // only the write that flips NULL→now() notifies
        try { db_query("UPDATE booking_checkin SET submitted_at = COALESCE(submitted_at, now()) WHERE hold_id = :h", [':h' => $holdId]); } catch (Throwable $e) {}
        audit_log('checkin.submit', 'hold', $holdId, (string)($hold['guest_name'] ?? ''));
        try { send_checkin_completed(fetch_hold_for_guest($holdId), fetch_checkin($holdId)); }
        catch (Throwable $e) { error_log('[checkin] mail: ' . $e->getMessage()); }
    }
    return true;
}

/**
 * Resolve check-in write authority from the request. Returns [holdId, onlyGuestId|null, ref|null].
 *   onlyGuestId === null → lead/hold authority (may target any guest of the hold).
 *   onlyGuestId === <id> → co-guest authority (may target only that guest).
 * Exits 403 on failure. Requires includes/booking.php (verify_guest_ref/verify_guest_pass_token).
 */
function checkin_auth_context(): array {
    $ref = trim((string)($_POST['ref'] ?? $_GET['ref'] ?? ''));
    if ($ref !== '' && ($h = verify_guest_ref($ref)) !== false) return [$h, null, $ref];
    $g = trim((string)($_POST['g'] ?? $_GET['g'] ?? ''));
    if ($g !== '' && ($r = verify_guest_pass_token($g)) !== false) return [$r[0], $r[1], null];
    http_response_code(403); exit('Invalid link.');
}

/**
 * The guest row a write targets, enforcing the auth scope. Co-guest → their own row.
 * Lead → validated posted guest_id, else the (ensured) lead row. Exits 403 on mismatch.
 */
function checkin_target_guest_id(int $holdId, ?int $onlyGuestId): int {
    if ($onlyGuestId !== null) return $onlyGuestId;
    $gid = (int)($_POST['guest_id'] ?? 0);
    if ($gid > 0) {
        $ok = db_query('SELECT 1 FROM checkin_guests WHERE id = :g AND hold_id = :h', [':g' => $gid, ':h' => $holdId])->fetchColumn();
        if (!$ok) { http_response_code(403); exit('Guest not in this booking.'); }
        return $gid;
    }
    db_query("INSERT INTO checkin_guests (hold_id, is_lead) VALUES (:h, TRUE) ON CONFLICT (hold_id) WHERE is_lead DO NOTHING", [':h' => $holdId]);
    return (int)db_query('SELECT id FROM checkin_guests WHERE hold_id = :h AND is_lead', [':h' => $holdId])->fetchColumn();
}
