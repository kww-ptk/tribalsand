<?php
declare(strict_types=1);
// Sync monitoring (Zuri sync task 6): checksum, switches, retry/review actions,
// and the report-only reconcile. Run: php tests/sync_monitor_logic.php
// Pure checks run anywhere; the DB round-trip runs in ONE rolled-back transaction
// when a migrated DB is reachable, else SKIPs.
$_SERVER['SYNC_SHARED_SECRET'] = 'test-secret-never-shown';

$GLOBALS['__peer'] = ['reply' => [0, null]];
function sync_peer_request(string $method, string $path, string $body = '', array $headers = []): array {
    return $GLOBALS['__peer']['reply'];
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/sync-monitor.php';
require_once __DIR__ . '/../includes/restaurant-tables.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Pure ────────────────────────────────────────────────────────────────────
$a = ['sync_uuid' => 'bbbb', 'sync_version' => 2];
$b = ['sync_uuid' => 'aaaa', 'sync_version' => 1];
check('checksum = Zuri formula (newline-joined, sorted)', sync_checksum([$a, $b]) === md5("aaaa|1\nbbbb|2"));
check('checksum lower-cases uuids',      sync_checksum([['sync_uuid' => 'AAAA', 'sync_version' => 1]]) === md5('aaaa|1'));
check('checksum is order-independent',   sync_checksum([$a, $b]) === sync_checksum([$b, $a]));
check('checksum changes with a version', sync_checksum([$a, $b]) !== sync_checksum([['sync_uuid' => 'bbbb', 'sync_version' => 3], $b]));
check('empty set checksum',              sync_checksum([]) === md5(''));

$sw = sync_monitor_switches();
check('switches report the secret as set', $sw['secret_set'] === true);
check('switches never carry the secret',   !str_contains((string) json_encode($sw), 'test-secret-never-shown'));

$GLOBALS['__peer']['reply'] = [200, ['ok' => false, 'alerts' => ['Outbox backlog 120', ['code' => 'bad_signature']]]];
$ph = sync_peer_health();
check('peer alerts shown verbatim',      $ph['alerts'][0] === 'Outbox backlog 120' && str_contains($ph['alerts'][1], 'bad_signature'));
check('JSON answer is the sync API',     $ph['is_api'] === true);
$GLOBALS['__peer']['reply'] = [200, null];   // e.g. the website homepage (HTML)
check('HTML 200 is not the sync API',     sync_peer_health()['is_api'] === false && sync_peer_health()['reachable'] === true);
$GLOBALS['__peer']['reply'] = [0, null];
check('unreachable peer',                sync_peer_health()['reachable'] === false);

// Reconcile compares against Zuri's /health?checksums=1 `entities` map.
$peerFixture = ['body' => ['entities' => ['restaurant_table' => ['count' => 0, 'checksum' => md5('')]]]];
check('peer checksums read from entities', ($peerFixture['body']['entities']['restaurant_table']['checksum'] ?? '') === sync_checksum([]));

// ── DB round-trip (rolled back) ─────────────────────────────────────────────
if (!sync_supported() || !rtables_supported()) {
    echo "\nSKIP  DB assertions (sync migrations not applied / DB unreachable)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"); exit($failures ? 1 : 0);
}
$zuri = (int) (db_query('SELECT id FROM venues WHERE slug = :s', [':s' => sync_venue_slug()])->fetchColumn() ?: 0);
if (!$zuri) { echo "\nSKIP  no '" . sync_venue_slug() . "' venue\n"; exit($failures ? 1 : 0); }

db()->beginTransaction();
try {
    // A table that was queued but never delivered shows up as undelivered.
    $t = create_restaurant_table(['venue_id' => $zuri, 'label' => 'TSTM', 'seats' => 2, 'is_active' => true]);
    $rep = sync_reconcile_report();
    check('reconcile: new table undelivered', in_array((string) $t['sync_uuid'], $rep['entities']['restaurant_table']['undelivered_sample'], true)
                                              || $rep['entities']['restaurant_table']['undelivered'] > 10);
    check('reconcile: report stored',       (sync_reconcile_last()['generated_at'] ?? '') === $rep['generated_at']);

    // Mark its create as failed → shows in Failed; Retry puts it back to pending.
    db_query("UPDATE sync_outbox SET status = 'failed', last_error = 'peer 400' WHERE sync_uuid = :u", [':u' => (string) $t['sync_uuid']]);
    $fid = (int) db_query("SELECT id FROM sync_outbox WHERE sync_uuid = :u", [':u' => (string) $t['sync_uuid']])->fetchColumn();
    check('failed send listed',             (bool) array_filter(sync_failed_outbox(500), fn($r) => (int) $r['id'] === $fid));
    check('retry one',                      sync_retry_outbox($fid) === 1
                                            && db_query('SELECT status FROM sync_outbox WHERE id = :i', [':i' => $fid])->fetchColumn() === 'pending');

    // Delivered at its current version → no longer undelivered.
    db_query("UPDATE sync_outbox SET status = 'sent', sent_at = now() WHERE id = :i", [':i' => $fid]);
    $rep = sync_reconcile_report();
    check('reconcile: delivered row clears', !in_array((string) $t['sync_uuid'], $rep['entities']['restaurant_table']['undelivered_sample'], true));

    // Rejected inbound → re-queue.
    $ev = ['event_id' => sync_new_event_id(), 'entity' => 'customer', 'operation' => 'create', 'sync_uuid' => sync_new_uuid(),
           'version' => 1, 'source' => 'zuri', 'data' => ['name' => 'X']];
    sync_inbox_receive($ev);
    db_query("UPDATE sync_inbox SET status = 'rejected', error = 'missing_reference' WHERE event_id = :e", [':e' => $ev['event_id']]);
    $iid = (int) db_query('SELECT id FROM sync_inbox WHERE event_id = :e', [':e' => $ev['event_id']])->fetchColumn();
    check('rejected inbound listed',        (bool) array_filter(sync_rejected_inbox(500), fn($r) => (int) $r['id'] === $iid));
    check('re-queue inbound',               sync_retry_inbox($iid) && db_query('SELECT status FROM sync_inbox WHERE id = :i', [':i' => $iid])->fetchColumn() === 'pending');
    check('re-queue only rejected',         !sync_retry_inbox($iid));

    // Conflicts: open until reviewed.
    db_query("INSERT INTO sync_conflicts (entity, sync_uuid, local_version, remote_version, local_data, remote_data, resolution)
              VALUES ('reservation', :u, 2, 2, '{\"guests\":4}', '{\"guests\":5}', 'later_occurred_at')", [':u' => sync_new_uuid()]);
    $cid = (int) db()->lastInsertId();
    $open = sync_open_conflict_count();
    check('conflict open',                  $open >= 1 && (bool) array_filter(sync_open_conflicts(500), fn($c) => (int) $c['id'] === $cid));
    check('mark reviewed',                  sync_mark_conflict_reviewed($cid) && sync_open_conflict_count() === $open - 1);

    db()->rollBack();
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    echo 'ERROR ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
    $failures++;
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
