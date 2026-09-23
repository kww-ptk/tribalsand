<?php
declare(strict_types=1);
/**
 * Sync monitoring (S§9) — what admin/sync.php shows and bin/reconcile.php runs.
 *
 *   sync_monitor_switches()      the env switches, for display (never the secret)
 *   sync_peer_health()           Zuri's GET /health (signed) — its alerts[] shown as-is
 *   sync_failed_outbox() / sync_retry_outbox()    failed pushes + reset to pending
 *   sync_rejected_inbox() / sync_retry_inbox()    rejected inbound events + re-queue
 *   sync_open_conflicts() / sync_mark_conflict_reviewed()
 *   sync_reconcile_report()      report-only drift check (S§9), stored in settings
 *
 * Read-mostly; the only writes are the explicit retry / reviewed actions and the
 * stored reconcile report. Everything is pre-migration-safe via sync_supported().
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sync.php';
require_once __DIR__ . '/sync-mappers.php';

/** The switches as staff should see them. The shared secret is reported set/unset only. */
function sync_monitor_switches(): array {
    return [
        'SYNC_ENABLED'      => sync_enabled(),
        'SYNC_SHADOW'       => sync_env_bool('SYNC_SHADOW', false),
        'SYNC_TS_TO_ZURI'   => sync_env_bool('SYNC_TS_TO_ZURI', false),
        'SYNC_ZURI_TO_TS'   => sync_env_bool('SYNC_ZURI_TO_TS', false),
        'SYNC_RESERVATIONS' => sync_env_bool('SYNC_RESERVATIONS', false),
        'secret_set'        => sync_shared_secret() !== '',
        'peer_url'          => sync_peer_base_url(),
        'venue'             => sync_venue_slug(),
    ];
}

/**
 * Zuri's health snapshot. Returns ['reachable' => bool, 'code' => int,
 * 'body' => ?array, 'alerts' => list<string>]. Alerts are shown verbatim — they
 * are Zuri's own words about its side.
 */
function sync_peer_health(): array {
    [$code, $body] = sync_peer_request('GET', '/health');
    $alerts = [];
    foreach ((array) ($body['alerts'] ?? []) as $a) {
        $alerts[] = is_string($a) ? $a : (string) json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return ['reachable' => $code > 0, 'code' => $code, 'body' => $body, 'alerts' => $alerts];
}

function sync_failed_outbox(int $limit = 50): array {
    if (!sync_supported()) return [];
    return db_query(
        "SELECT id, event_id, entity, sync_uuid, operation, version, attempts, last_error, created_at
           FROM sync_outbox WHERE status = 'failed' ORDER BY id DESC LIMIT " . max(1, $limit)
    )->fetchAll();
}

/** Put failed pushes back in the queue. $id = null → all failed. Returns rows reset. */
function sync_retry_outbox(?int $id = null): int {
    if (!sync_supported()) return 0;
    $where = "status = 'failed'" . ($id !== null ? ' AND id = :id' : '');
    return db_query(
        "UPDATE sync_outbox SET status = 'pending', attempts = 0, next_retry_at = now(), last_error = NULL WHERE $where",
        $id !== null ? [':id' => $id] : []
    )->rowCount();
}

function sync_rejected_inbox(int $limit = 50): array {
    if (!sync_supported()) return [];
    return db_query(
        "SELECT id, event_id, entity, sync_uuid, operation, version, error, received_at
           FROM sync_inbox WHERE status = 'rejected' ORDER BY id DESC LIMIT " . max(1, $limit)
    )->fetchAll();
}

/** Re-queue one rejected inbound event (e.g. after its missing customer arrived). */
function sync_retry_inbox(int $id): bool {
    if (!sync_supported()) return false;
    $retryCols = db_query(
        "SELECT count(*) FROM information_schema.columns
          WHERE table_schema = 'public' AND table_name = 'sync_inbox' AND column_name IN ('attempts', 'next_retry_at')"
    )->fetchColumn() == 2 ? ', attempts = 0, next_retry_at = NULL' : '';
    return db_query(
        "UPDATE sync_inbox SET status = 'pending', error = NULL{$retryCols} WHERE id = :id AND status = 'rejected'",
        [':id' => $id]
    )->rowCount() > 0;
}

/** Conflicts nobody has reviewed yet (the applier stores both sides; S§6). */
function sync_open_conflicts(int $limit = 50): array {
    if (!sync_supported()) return [];
    return db_query(
        "SELECT * FROM sync_conflicts WHERE resolved_at IS NULL ORDER BY id DESC LIMIT " . max(1, $limit)
    )->fetchAll();
}

function sync_open_conflict_count(): int {
    if (!sync_supported()) return 0;
    return (int) db_query('SELECT count(*) FROM sync_conflicts WHERE resolved_at IS NULL')->fetchColumn();
}

function sync_mark_conflict_reviewed(int $id): bool {
    if (!sync_supported()) return false;
    return db_query('UPDATE sync_conflicts SET resolved_at = now() WHERE id = :id AND resolved_at IS NULL', [':id' => $id])->rowCount() > 0;
}

/**
 * S§9 checksum over a set of rows: md5 of "uuid|version" lines sorted by uuid —
 * the same value as Postgres
 *   md5(string_agg(sync_uuid || '|' || sync_version, ',' ORDER BY sync_uuid))
 * so either side can compute it in SQL or code. Pure.
 */
function sync_checksum(array $rows): string {
    $lines = [];
    foreach ($rows as $r) $lines[(string) $r['sync_uuid']] = $r['sync_uuid'] . '|' . (int) $r['sync_version'];
    ksort($lines, SORT_STRING);
    return md5(implode(',', $lines));
}

/**
 * Report-only drift check for the entities WE own (menu_category, menu_item,
 * restaurant_table, opening_hours) of the synced venue:
 *   • count + checksum of the live rows (comparable with Zuri once it exposes the
 *     same checksum — its /health `checksums` map is used when present)
 *   • undelivered: live rows whose CURRENT version has no `sent` outbox event —
 *     edits that never reached Zuri (never queued, or stuck/failed). Unpriced
 *     items are not counted (they are held back by design).
 * Changes nothing except storing the report in settings (sync_reconcile_last).
 */
function sync_reconcile_report(?array $peerHealth = null): array {
    $report = ['generated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'venue' => sync_venue_slug(), 'entities' => [], 'ok' => true];
    if (!sync_supported()) return ['ok' => false, 'error' => 'sync not migrated'] + $report;

    $peerSums = is_array($peerHealth['body']['checksums'] ?? null) ? $peerHealth['body']['checksums'] : null;
    foreach (['menu_category', 'menu_item', 'restaurant_table', 'opening_hours'] as $entity) {
        $rows = sync_menu_rows($entity);
        if ($entity === 'menu_item') $rows = array_values(array_filter($rows, fn($r) => sync_menu_item_skip_reason($r) === ''));
        $sent = [];
        foreach (db_query(
            "SELECT sync_uuid::text AS u, max(version) AS v FROM sync_outbox WHERE status = 'sent' AND entity = :e GROUP BY sync_uuid",
            [':e' => $entity]
        )->fetchAll() as $s) $sent[$s['u']] = (int) $s['v'];
        $undelivered = [];
        foreach ($rows as $r) {
            if (($sent[(string) $r['sync_uuid']] ?? 0) < (int) $r['sync_version']) $undelivered[] = (string) $r['sync_uuid'];
        }
        $sum  = sync_checksum($rows);
        $peer = $peerSums[$entity] ?? null;
        $match = is_array($peer) ? (($peer['checksum'] ?? null) === $sum) : null;
        $report['entities'][$entity] = [
            'count' => count($rows), 'checksum' => $sum,
            'undelivered' => count($undelivered), 'undelivered_sample' => array_slice($undelivered, 0, 10),
            'peer_count' => is_array($peer) ? ($peer['count'] ?? null) : null, 'peer_match' => $match,
        ];
        if ($undelivered || $match === false) $report['ok'] = false;
    }
    $report['peer_checksums'] = $peerSums !== null;
    try { set_setting('sync_reconcile_last', (string) json_encode($report, JSON_UNESCAPED_SLASHES)); }
    catch (Throwable $e) { error_log('[sync-reconcile] could not store report: ' . $e->getMessage()); }
    return $report;
}

/** The last stored reconcile report, or null. */
function sync_reconcile_last(): ?array {
    try { $j = setting('sync_reconcile_last', ''); } catch (Throwable $e) { return null; }
    $r = $j !== '' ? json_decode($j, true) : null;
    return is_array($r) ? $r : null;
}
