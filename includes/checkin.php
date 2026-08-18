<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';   // is_owner(), is_manager()
require_once __DIR__ . '/team.php';   // staff_can_hold()

/** Fixed step catalog. Array order = wizard order. */
function checkin_step_catalog(): array {
    return [
        'arrival'  => ['label' => 'How you’ll arrive',    'default_required' => false],
        'transfer' => ['label' => 'Transfers',            'default_required' => false],
        'passport' => ['label' => 'Passport & identity',  'default_required' => true],
        'dietary'  => ['label' => 'Dietary requirements', 'default_required' => false],
        'requests' => ['label' => 'Special requests',     'default_required' => false],
        'waiver'   => ['label' => 'Waiver & indemnity',   'default_required' => true],
    ];
}

/** The three ways a guest can arrive. Array order = radio order in the wizard. */
function checkin_arrival_modes(): array {
    return [
        'flight' => 'By air',
        'road'   => 'By road',
        'other'  => 'Something else',
    ];
}

/**
 * The arrival mode to reason with. A row whose arrival_mode is unset or
 * unrecognised is pre-add_checkin_arrival.sql (or was never answered), and
 * the form it came from was flight-only — so it reads as 'flight'. Note the
 * guard is on the VALUE, not on the column existing: the migration adds the
 * column with no backfill, so legacy rows have it present and NULL. Pure.
 */
function checkin_effective_mode(?array $data): string {
    $mode = trim((string)(($data ?? [])['arrival_mode'] ?? ''));
    return array_key_exists($mode, checkin_arrival_modes()) ? $mode : 'flight';
}

/**
 * Airports offered in the arrival dropdown. Keys are the stored value (so the
 * select round-trips), values are the guest-facing label. The wizard adds an
 * "Other" choice that writes free text into the same arrival_airport column.
 */
function checkin_airports(): array {
    return [
        'Vipingo' => 'Vipingo',
        'Malindi' => 'Malindi (MYD)',
        'Mombasa' => 'Mombasa — Moi Intl (MBA)',
    ];
}

/**
 * Is the arrival step complete? The step now asks only HOW the guest arrives —
 * the airport and flight details moved to the transfer step, and the desired
 * check-in time is deliberately optional (the guest is warned, never blocked).
 * So choosing a mode is the whole requirement.
 *
 * A row with no mode is pre-add_checkin_arrival.sql and keeps its old rule, so
 * an unmigrated deployment does not suddenly mark every arrival step incomplete.
 * Pure.
 */
function checkin_arrival_complete(?array $data): bool {
    $d    = $data ?? [];
    $mode = trim((string)($d['arrival_mode'] ?? ''));
    if ($mode !== '') return array_key_exists($mode, checkin_arrival_modes());
    $has = fn($k) => trim((string)($d[$k] ?? '')) !== '';
    return $has('flight_number') && $has('arrival_at');
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

/** True once add_checkin_signature.sql is applied. Cached per-request. */
function checkin_signature_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db_query('SELECT waiver_signature FROM checkin_guests LIMIT 1'); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** True once add_checkin_arrival.sql is applied. Cached per-request. */
function checkin_arrival_mode_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db_query('SELECT arrival_mode FROM booking_checkin LIMIT 1'); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** True once add_property_arrival_time.sql is applied. Cached per-request. */
function checkin_property_arrival_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db_query('SELECT property_arrival_time FROM booking_checkin LIMIT 1'); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** True once add_departure_transfer.sql is applied (one atomic ALTER, so one column proves all three). Cached per-request. */
function checkin_departure_transfer_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db_query('SELECT departure_time FROM booking_checkin LIMIT 1'); $ok = true; }
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

/** The waiver terms the guest sees: the setting override, else the canonical default. */
function checkin_waiver_text(): string {
    $w = trim((string) setting('checkin_waiver_text', ''));
    return $w !== '' ? $w
        : 'I confirm the information provided is accurate and accept the terms of stay, indemnity and insurance requirements.';
}

/**
 * The property's check-in and check-out windows, plus the early/late policy note.
 * Stored in the key-value settings table (no migration) and edited in
 * admin/checkin-settings.php. Defaults apply to any unset key so the guest never
 * sees a blank window.
 */
function checkin_times(): array {
    $get = function (string $key, string $default): string {
        $v = trim((string) setting($key, ''));
        return $v !== '' ? $v : $default;
    };
    return [
        'ci_from' => $get('checkin_time_from',  '14:00'),
        'ci_to'   => $get('checkin_time_to',    '20:00'),
        'co_from' => $get('checkout_time_from', '10:00'),
        'co_to'   => $get('checkout_time_to',   '11:00'),
        'note'    => $get('checkin_early_late_note',
            'Early check-in and late check-out are available for a fee, subject to availability. Ask us and we’ll check and confirm.'),
    ];
}

/**
 * Is an expected arrival outside the check-in window? Returns 'early', 'late' or
 * '' (inside, unknown, or unparseable). Boundaries count as inside.
 *
 * Compared as minutes-from-midnight rather than strings, so '9:05' and '09:05'
 * behave the same. A malformed value returns '' — a false warning is worse than
 * none. Pure.
 */
function checkin_arrival_flag(?string $hhmm, string $from, string $to): string {
    $mins = function (?string $t): ?int {
        if ($t === null || !preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($t), $m)) return null;
        $h = (int)$m[1]; $i = (int)$m[2];
        if ($h > 23 || $i > 59) return null;
        return $h * 60 + $i;
    };
    $at = $mins($hhmm); $a = $mins($from); $b = $mins($to);
    if ($at === null || $a === null || $b === null) return '';
    if ($at < $a) return 'early';
    if ($at > $b) return 'late';
    return '';
}

/**
 * A posted yes/no answer as 'TRUE' | 'FALSE' | null (null = unanswered), ready to
 * bind to a boolean column. NEVER returns a PHP bool: db() runs with
 * PDO::ATTR_EMULATE_PREPARES, which renders a bound `false` as '', and Postgres
 * rejects '' for a boolean ("invalid input syntax for type boolean"). Answering
 * "No, I'll make my own way" fatalled the whole check-in in production this way
 * (PR #56). Every boolean the check-in write binds must come from here. Pure.
 */
function checkin_bool_param(array $post, string $key): ?string {
    if (!array_key_exists($key, $post) || $post[$key] === '') return null;
    return $post[$key] === '1' ? 'TRUE' : 'FALSE';
}

/**
 * A value safe to bind to a TIME column, or null. Strict on purpose: Postgres
 * rejects '25:00'/'99:99' and the exception would abort the check-in upsert,
 * which is atomic and carries the guest's whole form — one bad clock value would
 * lose their transfer and dietary answers too.
 *
 * Deliberately stricter than checkin_arrival_flag()'s parser (and its JS mirror),
 * which accepts stored 'HH:MM:SS' and is lenient because a missing early/late
 * warning beats a false one. Reading is forgiving; writing is not. Do not merge
 * the two. Pure.
 */
function checkin_clock_time(?string $v): ?string {
    if ($v === null) return null;
    $v = trim($v);
    return preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $v) ? $v : null;
}

/**
 * The guest's desired check-in time as HH:MM, or '' if unknown.
 *
 * This is both what the input field is rendered with and what the early/late
 * warning is computed from, so the server render and the live JS read the same
 * string and cannot contradict each other.
 *
 * Legacy healing: before the desired check-in time existed, a road/other guest's
 * arrival_at WAS the time they wanted their room, so it is used to prefill the
 * field. The next save then writes it into property_arrival_time and the row is
 * healed. Never for flight, and never when no mode is set (the legacy form was
 * flight-only) — arrival_at is a LANDING time there, hours before the guest
 * reaches the property.
 */
function checkin_desired_time(?array $data): string {
    $d  = $data ?? [];
    $pa = trim((string)($d['property_arrival_time'] ?? ''));
    if ($pa !== '') return substr($pa, 0, 5);

    $mode = trim((string)($d['arrival_mode'] ?? ''));
    if ($mode !== 'road' && $mode !== 'other') return '';

    $at = trim((string)($d['arrival_at'] ?? ''));
    $ts = $at !== '' ? strtotime($at) : false;
    return $ts !== false ? date('H:i', $ts) : '';
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
        case 'arrival':  return checkin_arrival_complete($data);
        case 'transfer':
            // pdo_pgsql returns native bools on PHP >= 8.1 (production is 8.2), so that
            // is the shape that actually arrives here. 't'/'f' is kept for older stacks
            // and '1'/'0' for anything reading straight from a form post.
            // Both legs read through the same coercion.
            $yes = function ($v): ?bool {
                if ($v === null) return null;
                if ($v === true  || $v === 't' || $v === '1' || $v === 1) return true;
                if ($v === false || $v === 'f' || $v === '0' || $v === 0) return false;
                return null;
            };
            $inb  = $yes($data['needs_transfer'] ?? null);
            $outb = checkin_departure_transfer_supported()
                ? $yes($data['needs_departure_transfer'] ?? null) : false;
            if ($inb === null || $outb === null) return false;
            if ($inb) {
                // Flying: the airport, flight and landing time ARE the detail.
                // Otherwise we need somewhere to collect them from. An unset
                // mode reads as flight — see checkin_effective_mode().
                $ok = checkin_effective_mode($data) === 'flight'
                    ? ($has('arrival_airport') && $has('flight_number') && $has('arrival_at'))
                    : $has('transfer_details');
                if (!$ok) return false;
            }
            if ($outb && !($has('departure_destination') && $has('departure_time'))) return false;
            return true;
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

/** A single guest row has signed the waiver (name + timestamp + a drawn signature). */
function checkin_guest_waiver_signed(?array $g): bool {
    return $g !== null
        && !empty($g['waiver_signed_at'])
        && trim((string)($g['waiver_signed_name'] ?? '')) !== ''
        && trim((string)($g['waiver_signature'] ?? '')) !== '';
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

/** How the signing surface was reached: 'reception' (admin's device) or 'own_link'. Pure. */
function checkin_signing_method(string $via): string {
    return $via === 'reception' ? 'reception' : 'own_link';
}

/** True once add_checkin_reference.sql is applied (reference ID + audit trail). Cached per-request. */
function checkin_reference_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db_query('SELECT waiver_reference FROM checkin_guests LIMIT 1'); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** True once the checkin_signing_audit table exists. Cached per-request. */
function checkin_signing_audit_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db_query('SELECT 1 FROM checkin_signing_audit LIMIT 1'); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** True once the audit hash-chain columns exist (add_checkin_record_integrity.sql). Cached per-request. */
function checkin_audit_hash_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db_query('SELECT prev_hash, row_hash FROM checkin_signing_audit LIMIT 1'); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** True once the identity-at-signing snapshot columns exist (add_checkin_record_integrity.sql). Cached per-request. */
function checkin_identity_snapshot_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db_query('SELECT waiver_name_snapshot FROM checkin_guests LIMIT 1'); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

/**
 * A unique, human-readable transaction/reference ID for one signed record.
 * Format: TSR-<hold>-<guest>-<4 random chars>. The hold/guest pair is already
 * unique per record; the random suffix makes the identifier unguessable so it
 * is safe to print on the guest-facing document. Uses the CSPRNG alphabet.
 */
function checkin_make_reference(int $holdId, int $guestId): string {
    return sprintf('TSR-%06d-%02d-%s', $holdId, $guestId, generate_access_code(4));
}

/**
 * The reference for a signed guest row, minting and persisting one if the row
 * predates the reference column (already-signed records get a controlled ID on
 * first view). Returns '' when the feature is unmigrated.
 */
function checkin_ensure_reference(array $guest): string {
    if (!checkin_reference_supported()) return '';
    $existing = trim((string)($guest['waiver_reference'] ?? ''));
    if ($existing !== '') return $existing;
    $holdId  = (int)($guest['hold_id'] ?? 0);
    $guestId = (int)($guest['id'] ?? 0);
    if (!$holdId || !$guestId) return '';
    // Retry on the (astronomically unlikely) unique collision on the random suffix.
    for ($try = 0; $try < 5; $try++) {
        $ref = checkin_make_reference($holdId, $guestId);
        try {
            db_query('UPDATE checkin_guests SET waiver_reference=:r WHERE id=:g AND hold_id=:h AND waiver_reference IS NULL',
                [':r'=>$ref, ':g'=>$guestId, ':h'=>$holdId]);
            $row = db_query('SELECT waiver_reference FROM checkin_guests WHERE id=:g', [':g'=>$guestId])->fetch();
            $stored = trim((string)($row['waiver_reference'] ?? ''));
            if ($stored !== '') return $stored;
        } catch (Throwable $e) { /* try again */ }
    }
    return '';
}

/**
 * The exact string an audit row is hashed over: its payload columns joined with a
 * unit separator. Excludes id and created_at so a stored row re-verifies from its
 * own payload alone. The field order is FIXED — changing it invalidates every
 * existing row_hash. Pure.
 */
function checkin_audit_canonical(array $r): string {
    $f = fn($k) => (string)($r[$k] ?? '');
    return implode("\x1f", [
        $f('reference'), $f('hold_id'), $f('guest_id'), $f('step'),
        $f('waiver_version'), $f('personal_link'), $f('ip'),
        $f('user_agent'), $f('method'), $f('detail'),
    ]);
}

/**
 * Append one step to the tamper-evident signing audit trail. Best-effort and
 * self-contained: guarded by the support check and wrapped so a logging failure
 * can never break the guest's signing or a record view. $ctx keys: reference,
 * hold_id, guest_id, waiver_version, personal_link, method, detail. IP and
 * user-agent are captured from the request.
 *
 * When the hash-chain columns exist, each row also stores prev_hash (the previous
 * row's row_hash, or a 64-zero genesis) and row_hash = sha256(prev_hash||payload),
 * so any later deletion or edit breaks the chain — see checkin_audit_verify().
 */
function checkin_log_signing_step(string $step, array $ctx): void {
    if (!checkin_signing_audit_supported()) return;
    try {
        $row = [
            'reference'      => ($ctx['reference'] ?? null) ?: null,
            'hold_id'        => (int)($ctx['hold_id'] ?? 0) ?: null,
            'guest_id'       => (int)($ctx['guest_id'] ?? 0) ?: null,
            'step'           => $step,
            'waiver_version' => ($ctx['waiver_version'] ?? null) ?: null,
            'personal_link'  => ($ctx['personal_link'] ?? null) ?: null,
            'ip'             => client_ip(),
            'user_agent'     => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            'method'         => ($ctx['method'] ?? null) ?: null,
            'detail'         => ($ctx['detail'] ?? null) ?: null,
        ];

        if (checkin_audit_hash_supported()) {
            $genesis  = str_repeat('0', 64);
            $prev     = db_query('SELECT row_hash FROM checkin_signing_audit WHERE row_hash IS NOT NULL ORDER BY id DESC LIMIT 1')->fetchColumn();
            $prevHash = ($prev !== false && $prev !== null && $prev !== '') ? (string)$prev : $genesis;
            $rowHash  = hash('sha256', $prevHash . "\x1e" . checkin_audit_canonical($row));
            db_query(
                'INSERT INTO checkin_signing_audit
                    (reference, hold_id, guest_id, step, waiver_version, personal_link, ip, user_agent, method, detail, prev_hash, row_hash)
                 VALUES (:ref, :h, :g, :step, :ver, :link, :ip, :ua, :m, :d, :ph, :rh)',
                [':ref'=>$row['reference'], ':h'=>$row['hold_id'], ':g'=>$row['guest_id'], ':step'=>$row['step'],
                 ':ver'=>$row['waiver_version'], ':link'=>$row['personal_link'], ':ip'=>$row['ip'], ':ua'=>$row['user_agent'],
                 ':m'=>$row['method'], ':d'=>$row['detail'], ':ph'=>$prevHash, ':rh'=>$rowHash]);
        } else {
            db_query(
                'INSERT INTO checkin_signing_audit
                    (reference, hold_id, guest_id, step, waiver_version, personal_link, ip, user_agent, method, detail)
                 VALUES (:ref, :h, :g, :step, :ver, :link, :ip, :ua, :m, :d)',
                [':ref'=>$row['reference'], ':h'=>$row['hold_id'], ':g'=>$row['guest_id'], ':step'=>$row['step'],
                 ':ver'=>$row['waiver_version'], ':link'=>$row['personal_link'], ':ip'=>$row['ip'], ':ua'=>$row['user_agent'],
                 ':m'=>$row['method'], ':d'=>$row['detail']]);
        }
    } catch (Throwable $e) { /* never let auditing break the flow */ }
}

/**
 * Walk the hash-chained audit trail in insertion order and confirm each row's
 * row_hash still equals sha256(prev_hash || payload), and (on the unfiltered
 * walk) that each row links to the previous one. Returns
 * ['ok'=>bool, 'checked'=>int, 'bad_id'=>?int]. Rows predating the chain
 * (NULL row_hash) are skipped. Read-only — never writes. Powers an admin
 * integrity check / certification of the record.
 */
function checkin_audit_verify(?int $holdId = null): array {
    if (!checkin_audit_hash_supported()) return ['ok'=>true, 'checked'=>0, 'bad_id'=>null];
    $genesis = str_repeat('0', 64);
    $sql = 'SELECT * FROM checkin_signing_audit WHERE row_hash IS NOT NULL';
    $p = [];
    if ($holdId !== null) { $sql .= ' AND hold_id = :h'; $p[':h'] = $holdId; }
    $sql .= ' ORDER BY id ASC';
    try { $rows = db_query($sql, $p)->fetchAll(); }
    catch (Throwable $e) { return ['ok'=>true, 'checked'=>0, 'bad_id'=>null]; }

    $checked = 0; $expectedPrev = $genesis;
    foreach ($rows as $r) {
        $recomputed = hash('sha256', (string)$r['prev_hash'] . "\x1e" . checkin_audit_canonical($r));
        if (!hash_equals((string)$r['row_hash'], $recomputed)) return ['ok'=>false, 'checked'=>$checked, 'bad_id'=>(int)$r['id']];
        // Chain linkage only holds across the full trail (a per-hold filter skips
        // rows in between), so enforce it on the unfiltered walk only.
        if ($holdId === null) {
            if (!hash_equals((string)$r['prev_hash'], $expectedPrev)) return ['ok'=>false, 'checked'=>$checked, 'bad_id'=>(int)$r['id']];
            $expectedPrev = (string)$r['row_hash'];
        }
        $checked++;
    }
    return ['ok'=>true, 'checked'=>$checked, 'bad_id'=>null];
}

/** Passport identity fields whose change AFTER signing invalidates a signature. */
function checkin_identity_material_fields(): array {
    return ['passport_name', 'passport_number', 'nationality', 'passport_expiry'];
}

/**
 * Enforce re-consent after a material identity edit. If guest $guestId is already
 * signed and any material passport field in $new differs from the stored value,
 * void the signature: clear the signing evidence (KEEPING the reference and the
 * append-only audit history), reopen the booking's completion, and append a
 * 'signature_voided' audit step. Returns true iff a signature was voided.
 *
 * Compare-before-void is essential: the guest wizard re-posts the passport fields
 * on every per-step save, so voiding on any write would nuke a guest's own
 * signature when they save an unrelated step. Only a REAL change voids.
 *
 * MUST be called BEFORE the caller writes the new identity — it needs the stored
 * (old) values to compare. $new maps field => posted value; a field absent from
 * $new is left out of the comparison. $actor ('admin'|'guest') is recorded.
 */
function checkin_void_signature_if_identity_changed(int $holdId, int $guestId, array $new, string $actor): bool {
    if (!checkin_signature_supported() || $guestId <= 0) return false;
    $g = db_query('SELECT * FROM checkin_guests WHERE id=:g AND hold_id=:h', [':g'=>$guestId, ':h'=>$holdId])->fetch();
    if (!$g || !checkin_guest_waiver_signed($g)) return false;

    $norm = fn($v) => trim((string)($v ?? ''));
    $changed = [];
    foreach (checkin_identity_material_fields() as $f) {
        if (!array_key_exists($f, $new)) continue;
        $old = $norm($g[$f] ?? '');
        $cur = $norm($new[$f] ?? '');
        if ($f === 'passport_expiry') { $old = substr($old, 0, 10); $cur = substr($cur, 0, 10); }
        if ($old !== $cur) $changed[] = $f;
    }
    if (!$changed) return false;

    // Clear the signing evidence so the guest must re-sign (a re-sign re-snapshots
    // the corrected identity). Reference + audit rows are deliberately preserved.
    $clear = 'waiver_signed_at=NULL, waiver_signed_name=NULL, waiver_signature=NULL,
              waiver_terms_snapshot=NULL, waiver_version=NULL, waiver_signed_ip=NULL,
              waiver_signed_user_agent=NULL, waiver_signed_method=NULL';
    if (checkin_identity_snapshot_supported()) {
        $clear .= ', waiver_name_snapshot=NULL, waiver_passport_snapshot=NULL,
                    waiver_nationality_snapshot=NULL, waiver_passport_expiry_snapshot=NULL';
    }
    db_query("UPDATE checkin_guests SET $clear WHERE id=:g AND hold_id=:h", [':g'=>$guestId, ':h'=>$holdId]);

    // A signed→unsigned transition must lift a completed booking back to pending,
    // or it would read "Checked in ✓" over a guest who now needs to re-sign.
    db_query('UPDATE holds SET checkin_completed_at=NULL WHERE id=:h', [':h'=>$holdId]);

    checkin_log_signing_step('signature_voided', [
        'reference'      => (string)($g['waiver_reference'] ?? ''),
        'hold_id'        => $holdId,
        'guest_id'       => $guestId,
        'waiver_version' => (string)($g['waiver_version'] ?? ''),
        'personal_link'  => (string)($g['waiver_signed_link'] ?? ''),
        'method'         => $actor,
        'detail'         => 'Signature voided — identity changed after signing: ' . implode(', ', $changed),
    ]);
    if (function_exists('audit_log')) {
        audit_log('checkin.signature_void', 'hold', $holdId, 'guest ' . $guestId . ' (' . implode(',', $changed) . ')');
    }
    return true;
}

/**
 * The personal check-in link a guest used to reach the signing surface: the
 * lead's ref link or a co-guest's g link. Recorded as evidence of the link
 * issued. Returns '' when no token is known.
 */
function checkin_personal_link(?string $ref, ?string $gToken): string {
    if (!empty($gToken)) return site_url('/booking.php?g=' . urlencode($gToken));
    if (!empty($ref))    return site_url('/booking.php?ref=' . urlencode($ref) . '&view=checkin');
    return '';
}

/**
 * Integrity: only the signer may sign. A co-guest (onlyGuestId set) always signs their
 * own row; the lead (null) may sign only the lead row. Pure.
 */
function checkin_can_sign_self(?int $onlyGuestId, bool $targetIsLead): bool {
    return $onlyGuestId !== null || $targetIsLead;
}

/**
 * Which pieces of consent a signing attempt is missing. [] = ready to sign.
 * The returned strings are guest-facing sentence fragments ("agree to the
 * terms"), used verbatim by both the wizard's inline error and the server's
 * rejection message so the two can never drift apart.
 *
 * $alreadySigned = the guest already has a stored signature, so an empty
 * $signature means "left the existing one alone", not "refused to sign". Pure.
 */
function checkin_consent_missing(bool $agreed, string $typedName, string $signature, bool $alreadySigned = false): array {
    $missing = [];
    if (!$agreed)                                                $missing[] = 'agree to the terms';
    if (trim($typedName) === '')                                 $missing[] = 'type your full name';
    if (!$alreadySigned && !checkin_valid_signature($signature)) $missing[] = 'draw your signature';
    return $missing;
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

/**
 * Which state the co-guest self-service page renders for guest $me:
 *   'done'        — nothing left (passport where enabled + waiver signed),
 *   'review_sign' — details are complete; only the signature remains,
 *   'full'        — passport still needs to be provided.
 * Pure. $config is checkin_config()-shaped (per-step ['enabled'=>bool]).
 */
function checkin_coguest_view_state(?array $me, array $config): string {
    $passOk   = empty($config['passport']['enabled']) || checkin_guest_passport_complete($me);
    $waiverOk = empty($config['waiver']['enabled'])   || checkin_guest_waiver_signed($me);
    if ($passOk && $waiverOk) return 'done';
    return $passOk ? 'review_sign' : 'full';
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
 * Adult guest rows that are not yet fully checked in, in roster order. Children
 * are never included. The counterpart to checkin_party_complete_count(), which
 * counts the same rows the other way round. Pure.
 */
function checkin_outstanding_adults(array $guests, array $config): array {
    $out = [];
    foreach ($guests as $g) {
        if (!empty($g['is_child'])) continue;
        if (!checkin_guest_complete($g, $config)) $out[] = $g;
    }
    return $out;
}

/**
 * A guest's display label: their name, else "Guest N" by ROSTER position — never
 * by position in a filtered list, which would number a guest who had already
 * finished. $short returns the first word only, for sentences. Pure.
 */
function checkin_guest_label(?array $guest, array $adults, bool $short = false): string {
    $g = $guest ?? [];
    $n = trim((string)($g['passport_name'] ?? ''));
    if ($n !== '') return $short ? explode(' ', $n)[0] : $n;
    $gid = (int)($g['id'] ?? 0);
    $pos = null;
    if ($gid > 0) {
        foreach (array_values($adults) as $i => $a) {
            if ((int)($a['id'] ?? 0) === $gid) { $pos = $i; break; }
        }
    }
    return 'Guest ' . ($pos === null ? count($adults) + 1 : $pos + 1);
}

/**
 * Human list of who a party is still waiting on: named guests plus a count of
 * adult slots that have not been added to the roster at all ("2 more guests").
 * Returns '' when nothing is outstanding. Pure.
 */
function checkin_waiting_on_label(array $names, int $unnamedSlots): string {
    $parts = array_values($names);
    if ($unnamedSlots > 0) $parts[] = $unnamedSlots === 1 ? '1 more guest' : "{$unnamedSlots} more guests";
    if (!$parts) return '';
    if (count($parts) === 1) return $parts[0];
    $last = array_pop($parts);
    return implode(', ', $parts) . ' and ' . $last;
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
