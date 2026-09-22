<?php
declare(strict_types=1);
// Tribalsand ↔ Zuri sync — transport primitives.
// Run: php tests/sync_logic.php
// Pure logic always runs (HMAC, ownership, state machine, resolver, envelope).
// The outbox/inbox round-trip runs in ONE rolled-back transaction when a DB is
// reachable and add_restaurant_sync.sql has been applied, else SKIPs.
require_once __DIR__ . '/../includes/sync.php';
require_once __DIR__ . '/../includes/sync-mappers.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── HMAC (§5) ────────────────────────────────────────────────────────────────
$secret = 'test-shared-secret';
$ts   = (string) time();
$body = '{"events":[{"event_id":"evt_x"}]}';
$sig  = sync_sign($ts, $body, $secret);
check('sign is deterministic',            $sig === sync_sign($ts, $body, $secret));
check('sign is hex sha256',               (bool) preg_match('/^[0-9a-f]{64}$/', $sig));
check('sign changes with body',           $sig !== sync_sign($ts, $body . ' ', $secret));

// pass the secret explicitly so these asserts don't depend on env/parse_env cache
$now = (int) $ts;
check('verify accepts a good signature',  sync_verify_signature($ts, $body, sync_sign($ts, $body, $secret), $now, $secret)['ok'] === true);
check('verify rejects a bad signature',   sync_verify_signature($ts, $body, 'deadbeef', $now, $secret)['error'] === 'bad_signature');
check('verify rejects an empty signature',sync_verify_signature($ts, $body, '', $now, $secret)['error'] === 'bad_signature');
check('verify rejects a stale timestamp',  sync_verify_signature((string)($now - 400), $body, sync_sign((string)($now - 400), $body, $secret), $now, $secret)['error'] === 'bad_signature');
check('verify rejects a future timestamp', sync_verify_signature((string)($now + 400), $body, sync_sign((string)($now + 400), $body, $secret), $now, $secret)['error'] === 'bad_signature');
check('verify accepts within the window',  sync_verify_signature((string)($now - 299), $body, sync_sign((string)($now - 299), $body, $secret), $now, $secret)['ok'] === true);
check('verify 503 when no secret set',    sync_verify_signature($ts, $body, $sig, $now, '')['error'] === 'not_configured');

// ── Ownership rules (§1) ───────────────────────────────────────────────────
check('menu_item owned by tribalsand',    sync_entity_owner('menu_item') === 'tribalsand');
check('item_availability owned by zuri',  sync_entity_owner('item_availability') === 'zuri');
check('reservation is two-way (null)',    sync_entity_owner('reservation') === null);
check('peer may NOT write menu_item',     sync_peer_may_write('menu_item') === false);   // → 403 not_owner
check('peer may write item_availability', sync_peer_may_write('item_availability') === true);
check('peer may write reservation',       sync_peer_may_write('reservation') === true);
check('peer may NOT write opening_hours', sync_peer_may_write('opening_hours') === false);

// ── Reservation state machine (§6) — the load-bearing terminal guard ─────────
check('pending → confirmed allowed',      sync_reservation_transition_allowed('pending', 'confirmed'));
check('pending → cancelled allowed',      sync_reservation_transition_allowed('pending', 'cancelled'));
check('confirmed → seated allowed',       sync_reservation_transition_allowed('confirmed', 'seated'));
check('confirmed → no_show allowed',      sync_reservation_transition_allowed('confirmed', 'no_show'));
check('seated → completed allowed',       sync_reservation_transition_allowed('seated', 'completed'));
check('same state is idempotent',         sync_reservation_transition_allowed('confirmed', 'confirmed'));
check('cancelled NEVER revives',          sync_reservation_transition_allowed('cancelled', 'confirmed') === false);
check('no_show is terminal',              sync_reservation_transition_allowed('no_show', 'seated') === false);
check('completed is terminal',            sync_reservation_transition_allowed('completed', 'seated') === false);
check('pending → seated is illegal',      sync_reservation_transition_allowed('pending', 'seated') === false);

// ── Conflict resolver (§6) ───────────────────────────────────────────────────
$L = ['version' => 5, 'data' => ['price' => '349.00'], 'occurred_at' => 1000];
check('higher version applies',   sync_resolve('menu_item', $L, ['version' => 6, 'data' => ['price' => '359.00']])['decision'] === 'apply');
check('lower version is stale',   sync_resolve('menu_item', $L, ['version' => 4, 'data' => ['price' => '359.00']])['decision'] === 'stale');
check('equal + same data → noop', sync_resolve('menu_item', $L, ['version' => 5, 'data' => ['price' => '349.00']])['decision'] === 'noop');

$conflict = sync_resolve('menu_item', $L, ['version' => 5, 'data' => ['price' => '359.00'], 'occurred_at' => 2000]);
check('equal + diff + we own → local wins', $conflict['decision'] === 'conflict' && $conflict['winner'] === 'local' && $conflict['reason'] === 'owner_wins');

$avail = ['version' => 5, 'data' => ['sold_out' => false], 'occurred_at' => 1000];
$c2 = sync_resolve('item_availability', $avail, ['version' => 5, 'data' => ['sold_out' => true], 'occurred_at' => 2000]);
check('equal + diff + zuri owns → remote wins', $c2['winner'] === 'remote' && $c2['reason'] === 'owner_wins');

// two-way entity, no owner → later occurred_at, then zuri on a tie
$r1 = ['version' => 2, 'data' => ['notes' => 'a'], 'occurred_at' => 1000, 'status' => 'pending'];
$c3 = sync_resolve('reservation', $r1, ['version' => 2, 'data' => ['notes' => 'b'], 'occurred_at' => 2000, 'status' => 'pending']);
check('two-way conflict → later wins',   $c3['winner'] === 'remote' && $c3['reason'] === 'later_occurred_at');
$c4 = sync_resolve('reservation', $r1, ['version' => 2, 'data' => ['notes' => 'b'], 'occurred_at' => 1000, 'status' => 'pending']);
check('two-way exact tie → zuri wins',   $c4['winner'] === 'remote' && $c4['reason'] === 'zuri_wins');

// reservation status: a higher-version confirmed must NOT revive a cancelled booking
$cancelled = ['version' => 3, 'data' => [], 'occurred_at' => 1000, 'status' => 'cancelled'];
$revive = sync_resolve('reservation', $cancelled, ['version' => 9, 'data' => [], 'occurred_at' => 9999, 'status' => 'confirmed']);
check('cancelled stays cancelled vs late confirmed', $revive['decision'] === 'noop' && $revive['reason'] === 'illegal_transition');

// ── Envelope + id minting (§3) ───────────────────────────────────────────────
$ev = sync_make_event('menu_item', 'update', 'a3f1c2d4-5e6f-4a8b-9c10-1d2e3f4a5b6c', 7, ['price' => '349.00']);
check('envelope has all fields',   count(array_diff(['event_id','entity','operation','sync_uuid','version','source','occurred_at','deleted','data'], array_keys($ev))) === 0);
check('envelope source is us',     $ev['source'] === 'tribalsand');
check('envelope occurred_at is Z', str_ends_with($ev['occurred_at'], 'Z'));
check('event_id has evt_ prefix',  str_starts_with(sync_new_event_id(), 'evt_'));
check('event_ids are unique',      sync_new_event_id() !== sync_new_event_id());
check('new uuid looks like a uuid',(bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', sync_new_uuid()));

// ── event validation ────────────────────────────────────────────────────────
check('valid event passes',        sync_validate_event($ev) === '');
check('missing entity rejected',   sync_validate_event(['event_id'=>'e','operation'=>'update','sync_uuid'=>'u','version'=>1,'source'=>'zuri']) === 'missing_entity');
check('bad operation rejected',    sync_validate_event(['event_id'=>'e','entity'=>'menu_item','operation'=>'frobnicate','sync_uuid'=>'u','version'=>1,'source'=>'zuri']) === 'bad_operation');

// ── data equality ────────────────────────────────────────────────────────────
check('order-insensitive equality', sync_data_equal(['a'=>1,'b'=>2], ['b'=>2,'a'=>1]));
check('nested equality',             sync_data_equal(['x'=>['p'=>1,'q'=>2]], ['x'=>['q'=>2,'p'=>1]]));
check('detects a difference',        !sync_data_equal(['a'=>1], ['a'=>2]));

// ── Mappers (§3 / §11 — our menu → envelope) ─────────────────────────────────
check('money → decimal string',    sync_money(349) === '349.00' && sync_money('1000.5') === '1000.50');
check('money null stays null',      sync_money(null) === null && sync_money('') === null);

$item = sync_map_menu_item([
    'name' => 'Paneer Tikka', 'description' => null, 'price' => 349,
    'is_veg' => true, 'is_spicy' => true, 'is_available' => false, 'sort_order' => 2,
    'category_sync_uuid' => 'cat-uuid-1',
]);
check('item price is a string',     $item['price'] === '349.00');
check('item links category_uuid',   $item['category_uuid'] === 'cat-uuid-1');
check('item carries veg badge',     $item['is_veg'] === true);
check('item carries availability',  $item['is_available'] === false);
check('item null description',      $item['description'] === null);

$cat = sync_map_menu_category(['section' => 'drinks', 'name' => 'Cocktails', 'tag' => null, 'icon' => '🍹', 'sort_order' => 1, 'is_visible' => true, 'menu_sync_uuid' => 'menu-uuid-1']);
check('category links menu_uuid',   $cat['menu_uuid'] === 'menu-uuid-1');
check('category section preserved', $cat['section'] === 'drinks');

// ── DB round-trip (outbox push + inbox de-dupe) ──────────────────────────────
if (!sync_supported()) {
    echo "\nSKIP  DB assertions (add_restaurant_sync.sql not applied)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"); exit($failures ? 1 : 0);
}

db()->beginTransaction();
try {
    $uuid = sync_new_uuid();
    sync_outbox_push('menu_item', $uuid, 'update', ['price' => '349.00'], 7);
    $n = (int) db_query('SELECT count(*) FROM sync_outbox WHERE sync_uuid = :u', [':u' => $uuid])->fetchColumn();
    check('outbox_push writes one row', $n === 1);

    // loop prevention: no outbox row while applying
    $uuid2 = sync_new_uuid();
    SyncContext::applying(function () use ($uuid2) {
        check('isApplying is true inside applying()', SyncContext::isApplying() === true);
        sync_outbox_push('menu_item', $uuid2, 'update', ['price' => '10.00'], 1);
    });
    check('isApplying restored after applying()', SyncContext::isApplying() === false);
    $n2 = (int) db_query('SELECT count(*) FROM sync_outbox WHERE sync_uuid = :u', [':u' => $uuid2])->fetchColumn();
    check('outbox_push skipped while applying (loop prevention)', $n2 === 0);

    // inbox de-dupe: same event_id applied once
    $inEvent = sync_make_event('item_availability', 'update', sync_new_uuid(), 3, ['sold_out' => true]);
    $inEvent['source'] = 'zuri';
    $r1 = sync_inbox_receive($inEvent);
    $r2 = sync_inbox_receive($inEvent);
    check('inbox first receive accepted',   $r1['result'] === 'accepted');
    check('inbox redelivery is duplicate',  $r2['result'] === 'duplicate');

    // ownership: peer writing a menu_item is not_owner
    $bad = sync_make_event('menu_item', 'update', sync_new_uuid(), 1, ['price' => '1.00']);
    $bad['source'] = 'zuri';
    check('inbox rejects not_owner',        sync_inbox_receive($bad)['error'] === 'not_owner');

    db()->rollBack();
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    echo 'ERROR ' . $e->getMessage() . "\n";
    $failures++;
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
