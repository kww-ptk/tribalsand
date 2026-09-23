<?php
declare(strict_types=1);
// Inbound applier (Zuri sync task 4): sold-out, customers, reservations,
// the ordering race, stale/conflict/terminal paths and the loop guard.
// Run: php tests/sync_apply_logic.php
// Pure checks run anywhere; the DB round-trip runs in ONE rolled-back
// transaction when add_sync_applier.sql (and its predecessors) are applied, else SKIPs.

// Stub the peer before sync-apply.php defines the real one (function_exists-guarded).
$GLOBALS['__peer'] = ['calls' => [], 'reply' => [0, null]];
function sync_peer_request(string $method, string $path, string $body = '', array $headers = []): array {
    $GLOBALS['__peer']['calls'][] = [$method, $path];
    return $GLOBALS['__peer']['reply'];
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/sync-apply.php';
require_once __DIR__ . '/../includes/restaurant-tables.php';
require_once __DIR__ . '/../includes/reservations.php';   // create_reservation() in the "booking we created" case

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Pure: mappers ───────────────────────────────────────────────────────────
$c = sync_apply_map_customer(['name' => ' Amina ', 'phone' => '+254 700 1', 'source' => 'walk_in']);
check('customer: maps present keys only',  $c['cols'] === ['name' => 'Amina', 'phone' => '+254 700 1'] && $c['errors'] === []);
check('customer: blank name refused',      sync_apply_map_customer(['name' => ' '])['errors'] !== []);
check('customer: partial update allowed',  sync_apply_map_customer(['email' => 'a@b.co'])['errors'] === []);

$r = sync_apply_map_reservation(['date' => '2026-10-01', 'time' => '19:30:00', 'guests' => 4, 'preference' => 'Dinner',
    'special_requests' => 'Window seat', 'notes' => 'VIP', 'status' => 'confirmed', 'reference' => 'ZR-ABC123']);
check('reservation: maps to our columns',  $r['errors'] === [] && $r['cols']['reservation_date'] === '2026-10-01'
                                           && $r['cols']['reservation_time'] === '19:30' && $r['cols']['party_size'] === 4);
check('reservation: special_requests → notes, notes → staff_notes',
                                           $r['cols']['notes'] === 'Window seat' && $r['cols']['staff_notes'] === 'VIP');
check('reservation: bad date refused',     sync_apply_map_reservation(['date' => '2026-02-30'])['errors'] !== []);
check('reservation: bad time refused',     sync_apply_map_reservation(['time' => '25:00'])['errors'] !== []);
check('reservation: 0 guests refused',     sync_apply_map_reservation(['guests' => 0])['errors'] !== []);
check('reservation: unknown status refused', sync_apply_map_reservation(['status' => 'eaten'])['errors'] !== []);
check('reservation: unknown preference refused', sync_apply_map_reservation(['preference' => 'Brunch'])['errors'] !== []);
check('reservation: absent keys untouched', sync_apply_map_reservation(['status' => 'seated'])['cols'] === ['status' => 'seated']);
check('on our bookings Zuri owns status only (+assigned)',
                                           in_array('status', sync_apply_reservation_peer_keys_on_ours(), true)
                                           && !in_array('guests', sync_apply_reservation_peer_keys_on_ours(), true));
check('backoff doubles',                   sync_apply_backoff(1) === 10 && sync_apply_backoff(3) === 40);
check('event without source refused',      sync_apply_event(['event_id' => 'e', 'entity' => 'customer', 'operation' => 'create', 'sync_uuid' => 'u', 'version' => 1])['status'] === 'rejected');
check('event from ourselves refused',      sync_apply_event(['event_id' => 'e', 'entity' => 'customer', 'operation' => 'create', 'sync_uuid' => 'u', 'version' => 1, 'source' => 'tribalsand'])['error'] === 'bad source');
check('unsupported entity refused',        sync_apply_event(['event_id' => 'e', 'entity' => 'seat_inventory', 'operation' => 'update', 'sync_uuid' => 'u', 'version' => 1, 'source' => 'zuri'])['error'] === 'unsupported_entity');

// ── DB round-trip (rolled back) ─────────────────────────────────────────────
if (!sync_applier_supported() || !customers_supported() || !rtables_supported()) {
    echo "\nSKIP  DB assertions (add_sync_applier.sql / restaurant models not applied, or DB unreachable)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"); exit($failures ? 1 : 0);
}
$zuri = sync_apply_venue_id();
if (!$zuri) {
    echo "\nSKIP  DB assertions (no '" . sync_venue_slug() . "' venue)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"); exit($failures ? 1 : 0);
}

/** Push one Zuri event into the inbox, as /sync/v1/events would. */
function inbox(string $entity, string $op, string $uuid, int $v, array $data, ?string $at = null): string {
    $ev = ['event_id' => sync_new_event_id(), 'entity' => $entity, 'operation' => $op, 'sync_uuid' => $uuid,
           'version' => $v, 'source' => 'zuri', 'occurred_at' => $at ?? gmdate('Y-m-d\TH:i:s\Z'), 'deleted' => $op === 'delete', 'data' => $data];
    sync_inbox_receive($ev);
    return $ev['event_id'];
}
function inbox_row(string $eventId): array {
    return db_query('SELECT status, error, attempts FROM sync_inbox WHERE event_id = :e', [':e' => $eventId])->fetch() ?: [];
}
function res_row(string $uuid): array {
    return db_query('SELECT * FROM reservations WHERE sync_uuid = :u', [':u' => $uuid])->fetch() ?: [];
}
function ready_all(): void { db_query("UPDATE sync_inbox SET next_retry_at = now() - interval '1 second' WHERE status = 'pending'"); }

db()->beginTransaction();
try {
    db_query("UPDATE sync_inbox SET status = 'applied' WHERE status = 'pending'");   // isolate from real rows (rolled back)
    $outboxBefore = (int) db_query('SELECT count(*) FROM sync_outbox')->fetchColumn();

    // Customer create.
    $cu = sync_new_uuid();
    $e1 = inbox('customer', 'create', $cu, 1, ['name' => 'Amina Test', 'phone' => '+254700000111', 'email' => 'amina@example.com']);
    sync_apply_pending();
    check('customer create applied',        inbox_row($e1)['status'] === 'applied');
    check('customer stored as Zuri-owned',  (string) db_query('SELECT sync_source FROM customers WHERE sync_uuid = :u', [':u' => $cu])->fetchColumn() === 'zuri');
    check('customer in id map',             (bool) db_query("SELECT 1 FROM sync_id_map WHERE entity = 'customer' AND sync_uuid = :u", [':u' => $cu])->fetchColumn());
    $e1b = inbox('customer', 'update', $cu, 2, ['email' => null]);
    sync_apply_pending();
    check('customer partial update keeps the rest',
          db_query('SELECT name FROM customers WHERE sync_uuid = :u', [':u' => $cu])->fetchColumn() === 'Amina Test'
          && db_query('SELECT email FROM customers WHERE sync_uuid = :u', [':u' => $cu])->fetchColumn() === null);

    // Reservation create, linked to the customer and one of our tables.
    $tbl = create_restaurant_table(['venue_id' => $zuri, 'label' => 'TSTZ', 'seats' => 4, 'is_active' => true]);
    $ru = sync_new_uuid();
    $e2 = inbox('reservation', 'create', $ru, 1, ['reference' => 'ZR-T' . random_int(10000, 99999), 'customer_uuid' => $cu,
        'table_uuid' => (string) $tbl['sync_uuid'], 'date' => '2099-10-01', 'time' => '19:30', 'guests' => 4,
        'preference' => 'Dinner', 'special_requests' => 'Window', 'status' => 'pending', 'source' => 'website']);
    sync_apply_pending();
    $res = res_row($ru);
    check('reservation create applied',     inbox_row($e2)['status'] === 'applied' && $res !== []);
    check('reservation guest from customer', ($res['guest_name'] ?? '') === 'Amina Test' && ($res['guest_phone'] ?? '') === '+254700000111');
    check('reservation linked to table',    (int) ($res['table_id'] ?? 0) === (int) $tbl['id']);
    check('reservation on the Zuri venue',  (int) ($res['venue_id'] ?? 0) === $zuri && ($res['sync_source'] ?? '') === 'zuri');

    // Higher version → applied; lower → stale; equal + different → conflict (later wins).
    $e3 = inbox('reservation', 'update', $ru, 2, ['status' => 'confirmed', 'confirmed_at' => '2099-09-30T10:00:00Z']);
    sync_apply_pending();
    check('higher version applied',         res_row($ru)['status'] === 'confirmed' && (int) res_row($ru)['sync_version'] === 2);
    $e4 = inbox('reservation', 'update', $ru, 1, ['guests' => 9]);
    sync_apply_pending();
    check('lower version rejected stale',   inbox_row($e4)['status'] === 'rejected' && inbox_row($e4)['error'] === 'stale_version'
                                            && (int) res_row($ru)['party_size'] === 4);
    $conflictsBefore = (int) db_query('SELECT count(*) FROM sync_conflicts WHERE sync_uuid = :u', [':u' => $ru])->fetchColumn();
    $e5 = inbox('reservation', 'update', $ru, 2, ['guests' => 5], '2099-12-31T00:00:00Z');
    sync_apply_pending();
    check('equal version conflict logged',  (int) db_query('SELECT count(*) FROM sync_conflicts WHERE sync_uuid = :u', [':u' => $ru])->fetchColumn() === $conflictsBefore + 1);
    check('later occurred_at wins',         (int) res_row($ru)['party_size'] === 5);

    // Terminal never revives.
    inbox('reservation', 'update', $ru, 3, ['status' => 'cancelled', 'cancellation_reason' => 'Guest called']);
    sync_apply_pending();
    $e6 = inbox('reservation', 'update', $ru, 4, ['status' => 'confirmed']);
    sync_apply_pending();
    check('cancelled stays cancelled',      res_row($ru)['status'] === 'cancelled');
    check('revival logged terminal_state',  (bool) db_query("SELECT 1 FROM sync_conflicts WHERE sync_uuid = :u AND resolution = 'terminal_state'", [':u' => $ru])->fetchColumn()
                                            && str_contains((string) inbox_row($e6)['error'], 'terminal_state'));

    // Ordering race: the reservation arrives before its customer.
    $cu2 = sync_new_uuid(); $ru2 = sync_new_uuid();
    $e7 = inbox('reservation', 'create', $ru2, 1, ['customer_uuid' => $cu2, 'date' => '2099-10-02', 'time' => '13:00', 'guests' => 2, 'status' => 'pending']);
    $e7b = inbox('reservation', 'update', $ru2, 2, ['status' => 'confirmed']);
    sync_apply_pending();
    check('waits for a missing customer',   inbox_row($e7)['status'] === 'pending' && (int) inbox_row($e7)['attempts'] === 1 && res_row($ru2) === []);
    check('later event for it is held',     inbox_row($e7b)['status'] === 'pending' && (int) inbox_row($e7b)['attempts'] === 0);
    inbox('customer', 'create', $cu2, 1, ['name' => 'Late Customer']);
    sync_apply_pending();   // applies the customer; the reservation is still backing off
    ready_all();
    sync_apply_pending();
    check('applies once the customer lands', inbox_row($e7)['status'] === 'applied' && inbox_row($e7b)['status'] === 'applied'
                                            && res_row($ru2)['status'] === 'confirmed');

    // A reference that never arrives fails after the max attempts.
    $e8 = inbox('reservation', 'create', sync_new_uuid(), 1, ['customer_uuid' => sync_new_uuid(), 'date' => '2099-10-03', 'time' => '13:00', 'guests' => 2]);
    for ($i = 0; $i < SYNC_APPLY_MAX_ATTEMPTS; $i++) { ready_all(); sync_apply_pending(); }
    check('missing reference fails after max tries', inbox_row($e8)['status'] === 'rejected' && str_contains((string) inbox_row($e8)['error'], 'missing_reference'));

    // Unknown uuid + partial update → pull the record from Zuri.
    $ru3 = sync_new_uuid();
    $GLOBALS['__peer']['reply'] = [200, ['events' => [['sync_uuid' => $ru3, 'entity' => 'reservation', 'version' => 3,
        'data' => ['date' => '2099-10-04', 'time' => '20:00', 'guests' => 3, 'status' => 'confirmed']]]]];
    $e9 = inbox('reservation', 'update', $ru3, 3, ['status' => 'confirmed']);
    sync_apply_pending();
    check('unknown update pulls then inserts', inbox_row($e9)['status'] === 'applied' && (int) (res_row($ru3)['party_size'] ?? 0) === 3
                                            && str_contains((string) end($GLOBALS['__peer']['calls'])[1], 'sync_uuid=' . $ru3));
    $GLOBALS['__peer']['reply'] = [0, null];

    // A booking WE created: Zuri may change status, not the guest count.
    $ours = create_reservation(['venue_id' => $zuri, 'reservation_date' => '2099-10-05', 'reservation_time' => '19:00',
        'party_size' => 2, 'guest_name' => 'Our Guest', 'guest_phone' => '+254700000222', 'source' => 'staff']);
    db_query("UPDATE reservations SET sync_source = 'tribalsand' WHERE id = :i", [':i' => (int) $ours['id']]);
    $ou = (string) db_query('SELECT sync_uuid FROM reservations WHERE id = :i', [':i' => (int) $ours['id']])->fetchColumn();
    $e10 = inbox('reservation', 'update', $ou, 2, ['status' => 'confirmed', 'guests' => 8, 'reference' => 'ZR-OURS1']);
    sync_apply_pending();
    $o = res_row($ou);
    check('ours: status applied',           $o['status'] === 'confirmed' && $o['reference'] === 'ZR-OURS1');
    check('ours: non-shared field ignored', (int) $o['party_size'] === 2 && str_contains((string) inbox_row($e10)['error'], 'guests'));

    // Sold out: its own version; the menu item's version untouched.
    db_query("INSERT INTO menus (venue_id, slug, title, is_published, sort_order) VALUES (:v, :s, 'T', TRUE, 999)", [':v' => $zuri, ':s' => 'tst-apply-' . bin2hex(random_bytes(3))]);
    $mid = (int) db()->lastInsertId();
    db_query("INSERT INTO menu_categories (menu_id, section, name, sort_order) VALUES (:m, 'food', 'Mains', 1)", [':m' => $mid]);
    $cid = (int) db()->lastInsertId();
    db_query("INSERT INTO menu_items (category_id, name, price, sort_order) VALUES (:c, 'Prawns', 1200, 1)", [':c' => $cid]);
    $iid = (int) db()->lastInsertId();
    $iu  = (string) db_query('SELECT sync_uuid FROM menu_items WHERE id = :i', [':i' => $iid])->fetchColumn();
    $e11 = inbox('item_availability', 'update', $iu, 1, ['is_available' => false]);
    sync_apply_pending();
    $it = db_query('SELECT is_sold_out, sold_out_at, sold_out_version, sync_version, is_available FROM menu_items WHERE id = :i', [':i' => $iid])->fetch();
    check('sold out applied',               $it['is_sold_out'] === true && $it['sold_out_at'] !== null && (int) $it['sold_out_version'] === 1);
    check('sold out ≠ our Hidden toggle',   $it['is_available'] === true && (int) $it['sync_version'] === 1);
    inbox('item_availability', 'update', $iu, 2, ['is_available' => true]);
    sync_apply_pending();
    check('back in stock clears it',        db_query('SELECT is_sold_out FROM menu_items WHERE id = :i', [':i' => $iid])->fetchColumn() === false);
    check('unknown item rejected',          (function () { $e = inbox('item_availability', 'update', sync_new_uuid(), 1, ['is_available' => false]); sync_apply_pending(); return inbox_row($e)['status'] === 'rejected'; })());

    // Loop guard: nothing the applier wrote was queued back to Zuri.
    check('applier queued no outbox rows',  (int) db_query('SELECT count(*) FROM sync_outbox')->fetchColumn() === $outboxBefore + 1);   // +1 = the test table's own create
    check('deleted reservation hidden',     (function () use ($ru2, $zuri) {
        inbox('reservation', 'delete', $ru2, 9, []);
        sync_apply_pending();
        return !array_filter(fetch_reservations([$zuri]), fn($r) => $r['sync_uuid'] === $ru2);
    })());

    db()->rollBack();
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    echo 'ERROR ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
    $failures++;
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
