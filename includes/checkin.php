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
    try { db_query('SELECT require_checkin FROM holds LIMIT 1'); $ok = true; }
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

/** Badge descriptor for Frontdesk/Holds, or null when not required. */
function checkin_badge(array $hold): ?array {
    return match (checkin_state($hold)) {
        'complete' => ['label' => 'Checked in ✓',    'class' => 'ci-badge--done'],
        'pending'  => ['label' => 'Check-in pending', 'class' => 'ci-badge--pending'],
        default    => null,
    };
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
        case 'passport':
            return $lead !== null
                && trim((string)($lead['passport_name'] ?? '')) !== ''
                && trim((string)($lead['passport_number'] ?? '')) !== ''
                && trim((string)($lead['passport_file_key'] ?? '')) !== '';
        case 'dietary':  return $has('dietary');
        case 'requests': return $has('special_requests');
        case 'waiver':   return !empty($data['waiver_signed_at']) && trim((string)($data['waiver_signed_name'] ?? '')) !== '';
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
