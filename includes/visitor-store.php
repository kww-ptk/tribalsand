<?php
/**
 * Visitor registration — the DB side. Every read is pre-migration-safe
 * (visitor_reg_supported()). Pure rules: includes/visitor-registration.php.
 * Test: php tests/visitor_registration_logic.php
 */
declare(strict_types=1);
require_once __DIR__ . '/visitor-registration.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking.php';
require_once __DIR__ . '/storage.php';

const VISITOR_CODE_FAIL_MAX = 10;    // failed booking-code tries per IP per 10 minutes
const VISITOR_REGISTER_MAX  = 30;    // registrations per IP per hour (a bar's wifi is one IP)
const VISITOR_RECOVER_MAX   = 5;     // "no code" / "registered before" requests per IP per 10 minutes
const VISITOR_UPLOAD_MAX    = 30;    // ID photo uploads per IP per hour
const VISITOR_VERIFIED_TTL  = 7200;  // seconds a Turnstile pass is trusted in this session
const VISITOR_AUDIT_LOCK    = 734120;   // pg advisory lock key: one audit writer at a time

/** True once add_visitor_registration.sql has run (probes its LAST table). */
function visitor_reg_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { return $ok = (bool) db_query("SELECT to_regclass('public.visitor_signing_audit')")->fetchColumn(); }
    catch (Throwable $e) { return $ok = false; }
}

function visitor_session_verified(): bool {
    return (int)($_SESSION['vr_verified_at'] ?? 0) > time() - VISITOR_VERIFIED_TTL;
}
function visitor_mark_verified(): void { $_SESSION['vr_verified_at'] = time(); }

// ── Rate-limit log ──────────────────────────────────────────────────────────
function visitor_attempt_log(string $kind, string $subject = ''): void {
    try {
        db_query('INSERT INTO visitor_reg_attempts (ip, kind, subject) VALUES (:ip, :k, :s)',
            [':ip' => client_ip(), ':k' => $kind, ':s' => $subject !== '' ? $subject : null]);
    } catch (Throwable $e) { /* never block on a log failure */ }
}

/** Attempts of $kind in the last $minutes — by this IP, or by $subject when given. Fails open (0). */
function visitor_attempt_count(string $kind, int $minutes, ?string $subject = null): int {
    $sql = "SELECT COUNT(*) FROM visitor_reg_attempts WHERE kind = :k AND created_at > now() - (:m || ' minutes')::interval AND ";
    $p = [':k' => $kind, ':m' => (string)$minutes];
    if ($subject !== null) { $sql .= 'subject = :s'; $p[':s'] = $subject; }
    else                   { $sql .= 'ip = :ip';     $p[':ip'] = client_ip(); }
    try { return (int) db_query($sql, $p)->fetchColumn(); } catch (Throwable $e) { return 0; }
}

/** " AND <col> IN (…)" for a venue scope; '' for null (owner). */
function visitor_venue_in(string $col, ?array $venueIds, array &$p, string $pfx = 'vv'): string {
    if ($venueIds === null) return '';
    $ids = $venueIds ?: [-1]; $ph = [];
    foreach (array_values($ids) as $i => $v) { $n = ":{$pfx}{$i}"; $ph[] = $n; $p[$n] = (int)$v; }
    return " AND {$col} IN (" . implode(',', $ph) . ')';
}

// ── Places ──────────────────────────────────────────────────────────────────
function visitor_places(bool $activeOnly = true, ?array $venueIds = null): array {
    if (!visitor_reg_supported()) return [];
    $p = [];
    $sql = 'SELECT p.*, v.name AS venue_name FROM visitor_places p JOIN venues v ON v.id = p.venue_id WHERE TRUE'
         . ($activeOnly ? ' AND p.is_active' : '') . visitor_venue_in('p.venue_id', $venueIds, $p)
         . ' ORDER BY p.sort_order, p.name';
    try { return db_query($sql, $p)->fetchAll(); } catch (Throwable $e) { return []; }
}

function visitor_place(int $id): ?array {
    if (!visitor_reg_supported() || $id <= 0) return null;
    $r = db_query('SELECT p.*, v.name AS venue_name FROM visitor_places p JOIN venues v ON v.id = p.venue_id WHERE p.id = :id', [':id' => $id])->fetch();
    return $r ?: null;
}

/** Add ($id null) or edit a place. Returns its id. */
function visitor_place_save(?int $id, string $name, int $venueId, int $sort, bool $active): int {
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '' || mb_strlen($name) > 80) throw new InvalidArgumentException('Give the place a name (up to 80 letters).');
    if (!db_query('SELECT 1 FROM venues WHERE id = :v', [':v' => $venueId])->fetchColumn()) throw new InvalidArgumentException('Choose the property whose gate it is behind.');
    $sp = db()->inTransaction();
    if ($sp) db()->exec('SAVEPOINT visitor_place');
    try {
        if ($id) {
            db_query('UPDATE visitor_places SET name = :n, venue_id = :v, sort_order = :s, is_active = :a WHERE id = :id',
                [':n' => $name, ':v' => $venueId, ':s' => $sort, ':a' => $active ? 't' : 'f', ':id' => $id]);
        } else {
            $id = (int) db_query('INSERT INTO visitor_places (name, venue_id, sort_order, is_active) VALUES (:n, :v, :s, :a) RETURNING id',
                [':n' => $name, ':v' => $venueId, ':s' => $sort, ':a' => $active ? 't' : 'f'])->fetchColumn();
        }
        if ($sp) db()->exec('RELEASE SAVEPOINT visitor_place');
        return (int)$id;
    } catch (PDOException $e) {
        if ($sp) db()->exec('ROLLBACK TO SAVEPOINT visitor_place');
        if ($e->getCode() === '23505') throw new InvalidArgumentException('There is already a place called “' . $name . '”.');
        throw $e;
    }
}

// ── Visitors ────────────────────────────────────────────────────────────────
function visitor_find(int $id, bool $withRemoved = false): ?array {
    if (!visitor_reg_supported() || $id <= 0) return null;
    $r = db_query('SELECT * FROM registered_visitors WHERE id = :id' . ($withRemoved ? '' : ' AND removed_at IS NULL'), [':id' => $id])->fetch();
    return $r ?: null;
}

function visitor_find_by_token(string $tok): ?array {
    $id = visitor_pass_verify($tok);
    return $id ? visitor_find($id) : null;
}

/** An adult visitor by email or phone (any format). Signed ones first, newest first. */
function visitor_find_by_contact(string $s): ?array {
    if (!visitor_reg_supported()) return null;
    $s = trim($s);
    if (str_contains($s, '@')) { $where = 'LOWER(email) = :x'; $x = mb_strtolower($s); }
    else { $x = visitor_phone_key($s); if ($x === '') return null; $where = 'phone_key = :x'; }
    $r = db_query("SELECT * FROM registered_visitors WHERE {$where} AND parent_id IS NULL AND removed_at IS NULL
                    ORDER BY (waiver_signed_at IS NULL), id DESC LIMIT 1", [':x' => $x])->fetch();
    return $r ?: null;
}

/** $d = visitor_clean_input() + photo_key. */
function visitor_create(array $d, string $via, ?int $createdBy = null): int {
    return (int) db_query(
        'INSERT INTO registered_visitors (full_name, phone, phone_key, email, id_type, id_number, nationality, id_expiry, id_photo_key, created_via, created_by)
         VALUES (:n, :ph, :pk, :em, :t, :num, :nat, :exp, :photo, :via, :by) RETURNING id',
        [':n' => $d['full_name'], ':ph' => $d['phone'] ?: null, ':pk' => $d['phone_key'] ?: null, ':em' => $d['email'] ?: null,
         ':t' => $d['id_type'], ':num' => $d['id_number'] ?: null, ':nat' => $d['nationality'] ?: null,
         ':exp' => $d['id_expiry'] ?: null, ':photo' => ($d['photo_key'] ?? '') ?: null,
         ':via' => in_array($via, ['self', 'gate', 'invite'], true) ? $via : 'self', ':by' => $createdBy ?: null])->fetchColumn();
}

/** A refusal decided inside visitor_let_in()'s write section (the only exception it turns into a message besides "already on site"). */
final class VisitorRefusal extends RuntimeException {}

/** Queue of ID-photo files to delete once the caller's transaction commits. */
function &visitor_file_delete_queue(): array {
    static $q = [];
    return $q;
}
/** Keys waiting to be deleted (for tests / inspection). */
function visitor_pending_file_deletes(): array { return array_values(visitor_file_delete_queue()); }
/** Delete the queued files (call AFTER the surrounding transaction commits), then empty the queue. */
function visitor_flush_file_deletes(): void {
    $q = &visitor_file_delete_queue();
    foreach ($q as $key) { try { storage_delete_private((string)$key); } catch (Throwable $e) {} }
    $q = [];
}
/** Empty the queue without deleting (call when the surrounding transaction rolled back). */
function visitor_discard_file_deletes(): void { $q = &visitor_file_delete_queue(); $q = []; }

/**
 * Save new details. A signed visitor whose material identity changed loses the
 * signature (reference + audit rows kept) and must sign again. A new photo
 * (photo_key) replaces the old file. Returns true when a signature was voided.
 *
 * File deletion: when this function owns the transaction the old photo is deleted
 * after commit. When the caller already has a transaction open, the old key is
 * only QUEUED — callers that wrap visitor_update_identity() in their own
 * transaction must call visitor_flush_file_deletes() after commit and
 * visitor_discard_file_deletes() on rollback.
 */
function visitor_update_identity(int $id, array $d, string $actor): bool {
    $v = visitor_find($id);
    if (!$v) throw new RuntimeException('Visitor not found.');
    $changed = visitor_signed($v) ? visitor_identity_changed($v, $d) : [];
    $newPhoto = (string)($d['photo_key'] ?? '');
    $own = !db()->inTransaction();
    if ($own) db()->beginTransaction(); else db()->exec('SAVEPOINT visitor_ident');
    try {
        db_query('UPDATE registered_visitors SET full_name = :n, phone = :ph, phone_key = :pk, email = :em, id_type = :t,
                         id_number = :num, nationality = :nat, id_expiry = :exp, id_photo_key = COALESCE(:photo, id_photo_key)
                   WHERE id = :id',
            [':n' => $d['full_name'], ':ph' => $d['phone'] ?: null, ':pk' => $d['phone_key'] ?: null, ':em' => $d['email'] ?: null,
             ':t' => $d['id_type'], ':num' => $d['id_number'] ?: null, ':nat' => $d['nationality'] ?: null,
             ':exp' => $d['id_expiry'] ?: null, ':photo' => $newPhoto !== '' ? $newPhoto : null, ':id' => $id]);
        if ($changed) {
            db_query('UPDATE registered_visitors SET waiver_signature = NULL, waiver_signed_name = NULL, waiver_signed_at = NULL,
                             waiver_terms_snapshot = NULL, waiver_version = NULL, waiver_name_snapshot = NULL, waiver_id_type_snapshot = NULL,
                             waiver_id_number_snapshot = NULL, waiver_nationality_snapshot = NULL, waiver_id_expiry_snapshot = NULL,
                             signed_ip = NULL, signed_ua = NULL, signed_via = NULL
                       WHERE id = :id', [':id' => $id]);
        }
        if ($own) db()->commit(); else db()->exec('RELEASE SAVEPOINT visitor_ident');
    } catch (Throwable $e) {
        try { if ($own) { if (db()->inTransaction()) db()->rollBack(); } else db()->exec('ROLLBACK TO SAVEPOINT visitor_ident'); } catch (Throwable $e2) {}
        throw $e;
    }
    // Only once the writes are durable: drop the replaced photo file (queued when the caller owns the transaction).
    if ($newPhoto !== '' && !empty($v['id_photo_key']) && $v['id_photo_key'] !== $newPhoto) {
        if ($own) { try { storage_delete_private((string)$v['id_photo_key']); } catch (Throwable $e) {} }
        else      { $q = &visitor_file_delete_queue(); $q[] = (string)$v['id_photo_key']; }
    }
    if (!$changed) return false;
    visitor_log_step('signature_voided', ['reference' => (string)$v['waiver_reference'], 'visitor_id' => $id,
        'waiver_version' => (string)$v['waiver_version'], 'method' => $actor,
        'detail' => 'Signature voided — identity changed after signing: ' . implode(', ', $changed)]);
    return true;
}

/** Sign today's terms: frozen terms + identity snapshot + evidence. Returns the reference. */
function visitor_sign(int $id, string $signedName, string $signature, string $via): string {
    $v = visitor_find($id);
    if (!$v || !empty($v['parent_id'])) throw new RuntimeException('Visitor not found.');
    $signedName = mb_substr(trim($signedName), 0, 120);
    if ($signedName === '' || !checkin_valid_signature($signature)) throw new InvalidArgumentException('A typed name and a drawn signature are needed.');
    $terms = visitor_waiver_text();
    $ver   = waiver_version($terms);
    $ref   = trim((string)($v['waiver_reference'] ?? '')) ?: visitor_make_reference($id);   // the id makes it unique
    db_query('UPDATE registered_visitors SET waiver_signature = :sig, waiver_signed_name = :n, waiver_signed_at = now(),
                     waiver_terms_snapshot = :terms, waiver_version = :ver, waiver_reference = :ref,
                     waiver_name_snapshot = full_name, waiver_id_type_snapshot = id_type, waiver_id_number_snapshot = id_number,
                     waiver_nationality_snapshot = nationality, waiver_id_expiry_snapshot = id_expiry,
                     signed_ip = :ip, signed_ua = :ua, signed_via = :via
               WHERE id = :id AND removed_at IS NULL',
        [':sig' => $signature, ':n' => $signedName, ':terms' => $terms, ':ver' => $ver, ':ref' => $ref,
         ':ip' => client_ip(), ':ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
         ':via' => $via === 'gate' ? 'gate' : 'self', ':id' => $id]);
    visitor_log_step('signed', ['reference' => $ref, 'visitor_id' => $id, 'waiver_version' => $ver,
        'method' => $via === 'gate' ? 'gate' : 'self', 'detail' => 'Signed as ' . $signedName]);
    return $ref;
}

/**
 * Append one step to the visitor signing chain. One writer at a time (advisory
 * lock), in a SAVEPOINT when inside the caller's transaction, so a logging
 * failure never aborts the signing.
 */
function visitor_log_step(string $step, array $ctx): void {
    if (!visitor_reg_supported()) return;
    $row = [
        'reference'      => (string)($ctx['reference'] ?? ''),
        'visitor_id'     => (int)($ctx['visitor_id'] ?? 0),
        'step'           => $step,
        'waiver_version' => (string)($ctx['waiver_version'] ?? ''),
        'ip'             => client_ip(),
        'user_agent'     => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        'method'         => (string)($ctx['method'] ?? ''),
        'detail'         => (string)($ctx['detail'] ?? ''),
    ];
    $sp = db()->inTransaction();
    try {
        if ($sp) db()->exec('SAVEPOINT visitor_audit'); else db()->beginTransaction();
        db()->query('SELECT pg_advisory_xact_lock(' . VISITOR_AUDIT_LOCK . ')');
        $prev = (string)(db_query('SELECT row_hash FROM visitor_signing_audit ORDER BY id DESC LIMIT 1')->fetchColumn() ?: str_repeat('0', 64));
        db_query('INSERT INTO visitor_signing_audit (reference, visitor_id, step, waiver_version, ip, user_agent, method, detail, prev_hash, row_hash)
                  VALUES (:ref, :v, :step, :ver, :ip, :ua, :m, :d, :ph, :rh)',
            [':ref' => $row['reference'], ':v' => $row['visitor_id'], ':step' => $step, ':ver' => $row['waiver_version'],
             ':ip' => $row['ip'], ':ua' => $row['user_agent'], ':m' => $row['method'], ':d' => $row['detail'],
             ':ph' => $prev, ':rh' => visitor_audit_hash($prev, $row)]);
        if ($sp) db()->exec('RELEASE SAVEPOINT visitor_audit'); else db()->commit();
    } catch (Throwable $e) {
        try { if ($sp) db()->exec('ROLLBACK TO SAVEPOINT visitor_audit'); elseif (db()->inTransaction()) db()->rollBack(); } catch (Throwable $e2) {}
        error_log('[visitor-audit] ' . $e->getMessage());
    }
}

/** Walk the chain; on the unfiltered walk also check each row links to the one before. */
function visitor_audit_verify(?int $visitorId = null): array {
    if (!visitor_reg_supported()) return ['ok' => true, 'checked' => 0, 'bad_id' => null];
    $p = [];
    $sql = 'SELECT * FROM visitor_signing_audit' . ($visitorId !== null ? ' WHERE visitor_id = :v' : '') . ' ORDER BY id';
    if ($visitorId !== null) $p[':v'] = $visitorId;
    $prev = str_repeat('0', 64); $n = 0;
    foreach (db_query($sql, $p)->fetchAll() as $r) {
        if (!hash_equals((string)$r['row_hash'], visitor_audit_hash((string)$r['prev_hash'], $r))) return ['ok' => false, 'checked' => $n, 'bad_id' => (int)$r['id']];
        if ($visitorId === null) {
            if (!hash_equals((string)$r['prev_hash'], $prev)) return ['ok' => false, 'checked' => $n, 'bad_id' => (int)$r['id']];
            $prev = (string)$r['row_hash'];
        }
        $n++;
    }
    return ['ok' => true, 'checked' => $n, 'bad_id' => null];
}

// ── Group ───────────────────────────────────────────────────────────────────
function visitor_add_child(int $parentId, string $name, int $age): int {
    $name = mb_substr(trim(preg_replace('/\s+/u', ' ', $name) ?? ''), 0, 120);
    if ($name === '' || $age < 0 || $age > 17) throw new InvalidArgumentException('Give the child’s name and an age under 18.');
    return (int) db_query("INSERT INTO registered_visitors (full_name, parent_id, age, created_via) VALUES (:n, :p, :a, 'self') RETURNING id",
        [':n' => $name, ':p' => $parentId, ':a' => $age])->fetchColumn();
}

function visitor_invite_adult(int $leadId, string $name): int {
    $name = mb_substr(trim(preg_replace('/\s+/u', ' ', $name) ?? ''), 0, 120);
    if ($name === '') throw new InvalidArgumentException('Give the person’s name.');
    return (int) db_query("INSERT INTO registered_visitors (full_name, invited_by, created_via) VALUES (:n, :l, 'invite') RETURNING id",
        [':n' => $name, ':l' => $leadId])->fetchColumn();
}

/** Take someone out of the lead's group: a child or an unsigned invite is removed; a signed adult just leaves the group. */
function visitor_remove_member(int $leadId, int $memberId): bool {
    $m = visitor_find($memberId);
    if (!$m) return false;
    if ((int)($m['parent_id'] ?? 0) === $leadId || ((int)($m['invited_by'] ?? 0) === $leadId && !visitor_signed($m))) {
        return db_query('UPDATE registered_visitors SET removed_at = now() WHERE id = :id AND removed_at IS NULL', [':id' => $memberId])->rowCount() > 0;
    }
    if ((int)($m['invited_by'] ?? 0) === $leadId) {
        return db_query('UPDATE registered_visitors SET invited_by = NULL WHERE id = :id', [':id' => $memberId])->rowCount() > 0;
    }
    return false;
}

function visitor_group(int $leadId): array {
    $rows = db_query('SELECT * FROM registered_visitors WHERE (invited_by = :l OR parent_id = :l) AND removed_at IS NULL ORDER BY id', [':l' => $leadId])->fetchAll();
    return ['adults'   => array_values(array_filter($rows, fn($r) => empty($r['parent_id']))),
            'children' => array_values(array_filter($rows, fn($r) => !empty($r['parent_id'])))];
}

// ── Visits ──────────────────────────────────────────────────────────────────
/** Today's (or $date's) place and who comes along. Ids outside the lead's group are dropped. */
function visitor_choose_visit(int $visitorId, int $placeId, string $date, array $adultIds, array $childIds, string $via): int {
    $g = visitor_group($visitorId);
    $keep = fn(array $ids, array $rows): array => array_values(array_intersect(array_map('intval', $ids), array_map(fn($r) => (int)$r['id'], $rows)));
    $party = json_encode(['adults' => $keep($adultIds, $g['adults']), 'children' => $keep($childIds, $g['children'])]);
    return (int) db_query(
        'INSERT INTO visitor_visits (visitor_id, place_id, visit_date, party, created_via) VALUES (:v, :p, :d, CAST(:party AS jsonb), :via)
         ON CONFLICT (visitor_id, visit_date) DO UPDATE SET place_id = EXCLUDED.place_id, party = EXCLUDED.party
         RETURNING id',
        [':v' => $visitorId, ':p' => $placeId, ':d' => $date, ':party' => $party, ':via' => $via === 'gate' ? 'gate' : 'self'])->fetchColumn();
}

function visitor_visit_row(?array $r): ?array {
    if (!$r) return null;
    $party = json_decode((string)$r['party'], true) ?: [];
    $r['party'] = ['adults' => array_map('intval', $party['adults'] ?? []), 'children' => array_map('intval', $party['children'] ?? [])];
    return $r;
}

function visitor_visit_on(int $visitorId, string $date): ?array {
    return visitor_visit_row(db_query(
        'SELECT vv.*, p.name AS place_name, p.venue_id, v.name AS venue_name FROM visitor_visits vv
           JOIN visitor_places p ON p.id = vv.place_id JOIN venues v ON v.id = p.venue_id
          WHERE vv.visitor_id = :v AND vv.visit_date = :d', [':v' => $visitorId, ':d' => $date])->fetch() ?: null);
}

function visitor_latest_visit(int $visitorId): ?array {
    return visitor_visit_row(db_query(
        'SELECT vv.*, p.name AS place_name, p.venue_id FROM visitor_visits vv JOIN visitor_places p ON p.id = vv.place_id
          WHERE vv.visitor_id = :v ORDER BY vv.visit_date DESC LIMIT 1', [':v' => $visitorId])->fetch() ?: null);
}

/** The visit's party as rows still in the lead's group. */
function visitor_visit_party(?array $visit, int $leadId): array {
    if (!$visit) return ['adults' => [], 'children' => []];
    $g = visitor_group($leadId);
    $pick = fn(array $rows, array $ids): array => array_values(array_filter($rows, fn($r) => in_array((int)$r['id'], $ids, true)));
    return ['adults' => $pick($g['adults'], $visit['party']['adults']), 'children' => $pick($g['children'], $visit['party']['children'])];
}

// ── Bookings (step 1 of /register) ──────────────────────────────────────────
/** Booking code + typed name → where to go. Nothing about the booking is returned on a miss. */
function visitor_booking_lookup(string $code, string $name, string $today): array {
    $h = resolve_booking_by_code_only($code);
    if (!$h || !in_array((string)$h['status'], ['pending', 'confirmed'], true) || (string)$h['check_out'] < $today) return ['kind' => 'notfound', 'url' => ''];
    if ((string)$h['status'] === 'pending' && !empty($h['expires_at']) && strtotime((string)$h['expires_at']) < time()) return ['kind' => 'notfound', 'url' => ''];   // 24h ran out, expiry job hasn't cleared it yet
    $id = (int)$h['id'];
    if (visitor_booking_name_match($name, (string)$h['guest_name'])) {
        return ['kind' => 'lead', 'url' => '/booking.php?ref=' . urlencode(make_guest_ref($id)) . (checkin_required($h) ? '&view=checkin' : '')];
    }
    foreach (fetch_checkin_guests($id) as $g) {
        if (!empty($g['is_lead']) || !empty($g['is_child'])) continue;
        if (visitor_booking_name_match($name, (string)($g['passport_name'] ?? ''))) {
            return ['kind' => 'guest', 'url' => '/booking.php?g=' . urlencode(make_guest_pass_token($id, (int)$g['id']))];
        }
    }
    return ['kind' => 'nomatch', 'url' => ''];
}

/** Current bookings under an email, each with its portal link (for the "no code" email). */
function visitor_booking_links_for_email(string $email, string $today): array {
    $ids = db_query("SELECT id FROM holds WHERE LOWER(guest_email) = :e AND status IN ('pending','confirmed') AND check_out >= :d ORDER BY check_in LIMIT 5",
        [':e' => mb_strtolower(trim($email)), ':d' => $today])->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($ids as $id) {
        $h = fetch_hold_for_guest((int)$id);
        if ($h) $out[] = ['hold' => $h, 'url' => site_url('/booking.php?ref=' . urlencode(make_guest_ref((int)$id)))];
    }
    return $out;
}

// ── Gate ────────────────────────────────────────────────────────────────────
/** The visitor's open gate sign-in (any day), or null. */
function visitor_on_site(int $visitorId): ?array {
    if (!visitor_reg_supported()) return null;
    $r = db_query('SELECT * FROM visitors WHERE registered_visitor_id = :v AND time_out IS NULL ORDER BY time_in DESC LIMIT 1', [':v' => $visitorId])->fetch();
    return $r ?: null;
}

/** Everything the guard's card needs. null = not a valid pass (unknown id, or a child row). */
function visitor_gate_card(int $visitorId, string $today, ?array $venueIds): ?array {
    $v = visitor_find($visitorId, true);
    if (!$v || !empty($v['parent_id'])) return null;
    $ver   = waiver_version(visitor_waiver_text());
    $visit = visitor_visit_on($visitorId, $today);
    $party = visitor_visit_party($visit, $visitorId);
    $adults = array_map(fn($a) => ['id' => (int)$a['id'], 'name' => (string)$a['full_name'], 'current' => visitor_waiver_current($a, $ver)], $party['adults']);
    $inScope = $visit === null || $venueIds === null || in_array((int)$visit['venue_id'], array_map('intval', $venueIds), true);
    return [
        'visitor'  => $v, 'visit' => $visit, 'adults' => $adults, 'children' => $party['children'],
        'verdict'  => visitor_gate_verdict($v, $visit, $adults, visitor_waiver_current($v, $ver), $inScope),
        'in_scope' => $inScope, 'on_site' => visitor_on_site($visitorId),
        'people'   => visitor_people_count($adults, $party['children']),
    ];
}

/**
 * Let a visitor (and today's party) in: one row in the gate register.
 * Every refusal is decided BEFORE anything is written; the gate's own visit row
 * and the register row are then written together in one savepoint/transaction,
 * so a refused or failed let-in leaves no trace (a visit row would widen the
 * registry scope of that property's managers).
 */
function visitor_let_in(int $visitorId, string $today, ?int $placeId, int $byAdmin, ?array $venueIds): array {
    $card = visitor_gate_card($visitorId, $today, $venueIds);
    if (!$card) return ['ok' => false, 'msg' => 'This pass isn’t valid.'];
    if ($card['verdict']['level'] === 'stop') return ['ok' => false, 'msg' => $card['verdict']['msg']];
    if ($card['on_site']) return ['ok' => false, 'msg' => 'Still signed in since ' . date('D j M H:i', strtotime((string)$card['on_site']['time_in'])) . ' — sign them out first.'];
    $place = null;
    if ($card['visit'] === null) {
        $place = $placeId ? visitor_place($placeId) : null;
        $okPlace = $place && !empty($place['is_active']) && ($venueIds === null || in_array((int)$place['venue_id'], array_map('intval', $venueIds), true));
        if (!$okPlace) return ['ok' => false, 'msg' => 'Pick where they are going.'];
    }
    $v = $card['visitor'];
    $own = !db()->inTransaction();
    if ($own) db()->beginTransaction(); else db()->exec('SAVEPOINT visitor_in');
    $done = false;
    try {
        if ($place) {
            visitor_choose_visit($visitorId, (int)$place['id'], $today, [], [], 'gate');
            $card = visitor_gate_card($visitorId, $today, $venueIds);
            if (!$card || $card['verdict']['level'] === 'stop') {
                $msg = $card ? $card['verdict']['msg'] : 'This pass isn’t valid.';
                throw new VisitorRefusal($msg);
            }
        }
        $visit = $card['visit'];
        $newId = (int) db_query(
            "INSERT INTO visitors (venue_id, visitor_name, visiting, purpose, logged_by, registered_visitor_id, visit_id, people)
             VALUES (:ven, :n, :place, 'Registered visitor', :by, :rv, :visit, :ppl) RETURNING id",
            [':ven' => (int)$visit['venue_id'], ':n' => (string)$v['full_name'], ':place' => (string)$visit['place_name'],
             ':by' => $byAdmin ?: null, ':rv' => $visitorId, ':visit' => (int)$visit['id'], ':ppl' => $card['people']])->fetchColumn();
        if ($own) db()->commit(); else db()->exec('RELEASE SAVEPOINT visitor_in');
        $done = true;
    } catch (Throwable $e) {
        if (!$done) {
            try { if ($own) { if (db()->inTransaction()) db()->rollBack(); } else db()->exec('ROLLBACK TO SAVEPOINT visitor_in'); } catch (Throwable $e2) {}
        }
        if ($e instanceof PDOException && $e->getCode() === '23505') return ['ok' => false, 'msg' => 'Already on site.'];
        if ($e instanceof VisitorRefusal) return ['ok' => false, 'msg' => $e->getMessage()];
        throw $e;
    }
    if (function_exists('audit_log')) audit_log('visitor.in', 'visitor', $newId, (string)$v['full_name']);
    $n = $card['people'];
    return ['ok' => true, 'msg' => (string)$v['full_name'] . ' let in — ' . $n . ' ' . ($n === 1 ? 'person' : 'people') . ' to ' . (string)$visit['place_name'] . '.'];
}

// ── Admin registry ──────────────────────────────────────────────────────────
/** Visitors this account may see: owner all; others = visited one of their properties, or registered on their tablet. */
function visitor_scope_sql(?array $venueIds, int $me, array &$p): string {
    if ($venueIds === null) return '';
    $in = visitor_venue_in('pl.venue_id', $venueIds, $p, 'sc');
    $p[':me'] = $me;
    return " AND (rv.created_by = :me OR EXISTS (SELECT 1 FROM visitor_visits vv JOIN visitor_places pl ON pl.id = vv.place_id
                                                  WHERE vv.visitor_id = rv.id{$in}))";
}

function visitor_in_scope(int $id, ?array $venueIds, int $me): bool {
    if (!visitor_reg_supported()) return false;
    $p = [':id' => $id];
    $sql = 'SELECT 1 FROM registered_visitors rv WHERE rv.id = :id' . visitor_scope_sql($venueIds, $me, $p);
    return (bool) db_query($sql, $p)->fetchColumn();
}

function visitor_registry_list(?array $venueIds, int $me, string $q, int $per, int $offset): array {
    if (!visitor_reg_supported()) return ['rows' => [], 'total' => 0];
    $p = [];
    $where = 'rv.parent_id IS NULL AND rv.removed_at IS NULL' . visitor_scope_sql($venueIds, $me, $p);
    if ($q !== '') {
        $where .= ' AND (rv.full_name ILIKE :q OR rv.email ILIKE :q OR rv.phone ILIKE :q OR rv.waiver_reference ILIKE :q)';
        $p[':q'] = '%' . $q . '%';
    }
    $total = (int) db_query("SELECT COUNT(*) FROM registered_visitors rv WHERE {$where}", $p)->fetchColumn();
    $per = max(1, min(200, $per)); $offset = max(0, $offset);
    $rows = db_query("SELECT rv.*, lv.visit_date AS last_visit, lv.place_name AS last_place
                        FROM registered_visitors rv
                        LEFT JOIN LATERAL (SELECT vv.visit_date, pl.name AS place_name FROM visitor_visits vv
                                             JOIN visitor_places pl ON pl.id = vv.place_id
                                            WHERE vv.visitor_id = rv.id ORDER BY vv.visit_date DESC LIMIT 1) lv ON TRUE
                       WHERE {$where} ORDER BY COALESCE(lv.visit_date, rv.created_at::date) DESC, rv.id DESC
                       LIMIT {$per} OFFSET {$offset}", $p)->fetchAll();
    return ['rows' => $rows, 'total' => $total];
}

/** Gate sign-ins of this visitor, at properties the account covers. */
function visitor_visit_history(int $id, ?array $venueIds): array {
    $p = [':id' => $id];
    $sql = 'SELECT vs.time_in, vs.time_out, vs.visiting, vs.people, v.name AS venue_name FROM visitors vs
              JOIN venues v ON v.id = vs.venue_id WHERE vs.registered_visitor_id = :id'
         . visitor_venue_in('vs.venue_id', $venueIds, $p) . ' ORDER BY vs.time_in DESC LIMIT 50';
    return db_query($sql, $p)->fetchAll();
}

/** Remove a visitor: pass stops working, ID photo deleted; signature evidence and gate log kept. */
function visitor_remove(int $id): bool {
    $v = visitor_find($id);
    if (!$v) return false;
    db_query('UPDATE registered_visitors SET removed_at = now(), id_photo_key = NULL WHERE (id = :id OR parent_id = :id) AND removed_at IS NULL', [':id' => $id]);
    if (!empty($v['id_photo_key'])) { try { storage_delete_private((string)$v['id_photo_key']); } catch (Throwable $e) {} }
    if (function_exists('audit_log')) audit_log('visitor.remove', 'visitor', $id, (string)$v['full_name']);
    return true;
}
