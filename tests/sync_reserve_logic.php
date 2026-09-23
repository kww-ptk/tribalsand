<?php
declare(strict_types=1);
// Reservations outbound (Zuri sync task 5): the /reserve client, booking
// routing + fallback, and status changes queued for bookings that live on Zuri.
// Run: php tests/sync_reserve_logic.php
// Pure checks run anywhere (the HTTP call is stubbed); the DB round-trip runs in
// ONE rolled-back transaction when add_sync_applier.sql is applied, else SKIPs.

// Switch the reservations stage on for this process (parse_env reads $_SERVER first).
$_SERVER['SYNC_ENABLED'] = 'true';
$_SERVER['SYNC_RESERVATIONS'] = 'true';

$GLOBALS['__peer'] = ['calls' => [], 'reply' => [0, null]];
function sync_peer_request(string $method, string $path, string $body = '', array $headers = []): array {
    $GLOBALS['__peer']['calls'][] = ['method' => $method, 'path' => $path, 'body' => json_decode($body, true), 'headers' => $headers];
    return $GLOBALS['__peer']['reply'];
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/sync-reserve.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Pure: request body ──────────────────────────────────────────────────────
$b = sync_reserve_body(['reservation_date' => '2099-10-01', 'reservation_time' => '19:30:00', 'party_size' => '4',
    'guest_name' => ' Amina ', 'guest_phone' => '+254700000111', 'guest_email' => '', 'notes' => 'Window', 'preference' => 'Dinner'],
    'uuid-1', 'TSR-3-ABC');
check('body: contract keys',        array_keys($b) === ['date', 'time', 'guests', 'customer', 'sync_uuid', 'external_id', 'preference', 'requests']);
check('body: time HH:MM, guests int', $b['time'] === '19:30' && $b['guests'] === 4);
check('body: customer, empty email left out', $b['customer'] === ['name' => 'Amina', 'phone' => '+254700000111']);
check('body: guest notes → requests', $b['requests'] === 'Window' && !isset($b['notes']));

// ── Pure: alternatives ──────────────────────────────────────────────────────
$alts = sync_reserve_alternatives(['20:00', ['time' => '20:30:00'], ['date' => '2099-10-02', 'slot' => '19:00'], 'soon', ['x' => 1], 42]);
check('alternatives normalised, junk dropped', $alts === [['date' => null, 'time' => '20:00'], ['date' => null, 'time' => '20:30'], ['date' => '2099-10-02', 'time' => '19:00']]);
check('alternatives label',         sync_reserve_alternatives_label($alts, '2099-10-01') === '8:00 PM, 8:30 PM, Fri 2 Oct 7:00 PM');
check('no alternatives → empty',    sync_reserve_alternatives(null) === []);

// ── Pure: the client against a stubbed Zuri ─────────────────────────────────
$GLOBALS['__peer']['reply'] = [201, ['reservation' => ['sync_uuid' => 'uuid-1', 'reference' => 'ZR-AB12CD', 'status' => 'confirmed']]];
$r = sync_reserve_call($b, 'uuid-1');
$call = end($GLOBALS['__peer']['calls']);
check('201 → ok + reservation',     $r['ok'] === true && $r['reservation']['reference'] === 'ZR-AB12CD');
check('POSTs /reserve',             $call['method'] === 'POST' && $call['path'] === '/reserve' && $call['body']['sync_uuid'] === 'uuid-1');
check('sends Idempotency-Key',      in_array('Idempotency-Key: uuid-1', $call['headers'], true));
$GLOBALS['__peer']['reply'] = [409, ['error' => 'slot_unavailable', 'alternatives' => ['20:00']]];
$r = sync_reserve_call($b, 'uuid-1');
check('409 → slot_unavailable + alternatives', !$r['ok'] && $r['code'] === 'slot_unavailable' && $r['alternatives'][0]['time'] === '20:00');
$GLOBALS['__peer']['reply'] = [400, ['error' => 'invalid_payload', 'message' => 'guests']];
check('400 → invalid_payload',      sync_reserve_call($b, 'u')['code'] === 'invalid_payload');
$GLOBALS['__peer']['reply'] = [0, null];
check('no answer → unreachable',    sync_reserve_call($b, 'u')['code'] === 'unreachable');
$GLOBALS['__peer']['reply'] = [503, null];
check('5xx → http_503',             sync_reserve_call($b, 'u')['code'] === 'http_503');
$GLOBALS['__peer']['reply'] = [201, ['nope' => true]];
check('201 without a reservation is not ok', sync_reserve_call($b, 'u')['ok'] === false);

// ── Pure: labels + "is it on Zuri" ──────────────────────────────────────────
check('status label no_show',       reservation_status_label('no_show') === 'No-show' && reservation_status_label('seated') === 'Seated');
check('on Zuri: synced + our venue', reservation_on_zuri(['sync_last_at' => '2099-01-01', 'sync_uuid' => 'u', 'venue_slug' => sync_venue_slug()]));
check('not on Zuri: never synced',  !reservation_on_zuri(['sync_last_at' => null, 'sync_uuid' => 'u', 'venue_slug' => sync_venue_slug()]));
check('not on Zuri: other venue',   !reservation_on_zuri(['sync_last_at' => '2099-01-01', 'sync_uuid' => 'u', 'venue_slug' => 'maya-kobe']));

// ── DB round-trip (rolled back) ─────────────────────────────────────────────
if (!sync_applier_supported() || !reservations_extended_supported()) {
    echo "\nSKIP  DB assertions (add_sync_applier.sql not applied / DB unreachable)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"); exit($failures ? 1 : 0);
}
$zuri  = sync_apply_venue_id();
$other = (int) (db_query('SELECT id FROM venues WHERE slug <> :s ORDER BY id LIMIT 1', [':s' => sync_venue_slug()])->fetchColumn() ?: 0);
if (!$zuri || !$other) {
    echo "\nSKIP  DB assertions (need the '" . sync_venue_slug() . "' venue and one other)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"); exit($failures ? 1 : 0);
}
$booking = fn(int $v) => ['venue_id' => $v, 'reservation_date' => '2099-10-01', 'reservation_time' => '19:30', 'party_size' => 2,
                          'guest_name' => 'Reserve Test', 'guest_phone' => '+254700000333', 'source' => 'staff'];
function outbox_ops(string $uuid): array {
    return db_query('SELECT payload FROM sync_outbox WHERE sync_uuid = :u ORDER BY id', [':u' => $uuid])->fetchAll(PDO::FETCH_COLUMN);
}

db()->beginTransaction();
try {
    // Another venue never calls Zuri.
    $calls = count($GLOBALS['__peer']['calls']);
    $o = reservation_book($booking($other));
    check('other venue: local, no call', $o['ok'] && $o['via'] === 'local' && count($GLOBALS['__peer']['calls']) === $calls);
    check('local row stamped ours',      (string) db_query('SELECT sync_source FROM reservations WHERE id = :i', [':i' => (int) $o['reservation']['id']])->fetchColumn() === 'tribalsand');

    // Zuri accepts → stored with Zuri's reference, our uuid, on Zuri.
    $GLOBALS['__peer']['reply'] = [201, ['reservation' => ['reference' => 'ZR-T' . random_int(10000, 99999), 'status' => 'confirmed', 'duration_minutes' => 90]]];
    $z = reservation_book($booking($zuri));
    $row = $z['reservation'];
    $sent = end($GLOBALS['__peer']['calls'])['body'];
    check('zuri venue: booked on Zuri',  $z['ok'] && $z['via'] === 'zuri' && str_starts_with((string) $row['reference'], 'ZR-T'));
    check('local copy = sent uuid',      (string) $row['sync_uuid'] === $sent['sync_uuid'] && (string) $row['external_id'] === $sent['external_id']);
    check('local copy is ours + synced', $row['sync_source'] === 'tribalsand' && !empty($row['sync_last_at']) && $row['status'] === 'confirmed');
    check('local copy is on Zuri',       reservation_on_zuri($row));

    // Staff seat it → ONE outbox event carrying status only.
    check('seat allowed',                set_reservation_status((int) $row['id'], 'seated'));
    $ev = json_decode((string) (outbox_ops((string) $row['sync_uuid'])[0] ?? '{}'), true);
    check('seat queued a status event',  ($ev['entity'] ?? '') === 'reservation' && ($ev['data'] ?? null) === ['status' => 'seated']);
    check('event carries bumped version', (int) ($ev['version'] ?? 0) === 2);
    check('seated_at stamped',           fetch_reservation((int) $row['id'])['seated_at'] !== null);

    // Cancel carries the reason (shared field).
    $GLOBALS['__peer']['reply'] = [201, ['reservation' => ['reference' => 'ZR-U' . random_int(10000, 99999), 'status' => 'pending']]];
    $z2 = reservation_book($booking($zuri))['reservation'];
    set_reservation_status((int) $z2['id'], 'cancelled', 'Guest called');
    $ev2 = json_decode((string) (outbox_ops((string) $z2['sync_uuid'])[0] ?? '{}'), true);
    check('cancel sends status + reason', ($ev2['data'] ?? null) === ['status' => 'cancelled', 'cancellation_reason' => 'Guest called']);

    // Loop guard: a status change made while applying Zuri's event is not echoed.
    $GLOBALS['__peer']['reply'] = [201, ['reservation' => ['reference' => 'ZR-V' . random_int(10000, 99999), 'status' => 'pending']]];
    $z3 = reservation_book($booking($zuri))['reservation'];
    SyncContext::applying(fn() => set_reservation_status((int) $z3['id'], 'confirmed'));
    check('no echo while applying',      outbox_ops((string) $z3['sync_uuid']) === []);

    // Full → nothing stored; alternatives surface.
    $before = (int) db_query('SELECT count(*) FROM reservations')->fetchColumn();
    $GLOBALS['__peer']['reply'] = [409, ['alternatives' => ['20:00', '20:30']]];
    $f = reservation_book($booking($zuri));
    check('409: not booked, alternatives', !$f['ok'] && count($f['alternatives']) === 2);
    check('409: nothing stored',         (int) db_query('SELECT count(*) FROM reservations')->fetchColumn() === $before);

    // Zuri down → local fallback, flagged, never announced.
    $GLOBALS['__peer']['reply'] = [0, null];
    $d = reservation_book($booking($zuri));
    $dr = $d['reservation'];
    check('down: saved locally',          $d['ok'] && $d['via'] === 'local_fallback' && (int) ($dr['id'] ?? 0) > 0);
    check('down: flagged for staff',      str_starts_with((string) $dr['staff_notes'], 'NOT ON ZURI'));
    check('down: not treated as on Zuri', !reservation_on_zuri(fetch_reservation((int) $dr['id'])));
    set_reservation_status((int) $dr['id'], 'confirmed');
    check('down: status change not sent', outbox_ops((string) db_query('SELECT sync_uuid FROM reservations WHERE id = :i', [':i' => (int) $dr['id']])->fetchColumn()) === []);

    db()->rollBack();
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    echo 'ERROR ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
    $failures++;
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
