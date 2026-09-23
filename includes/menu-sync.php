<?php
declare(strict_types=1);
/**
 * Outbox hooks for every entity Tribalsand OWNS (Stage 2 of the Zuri sync, §4):
 * menu categories/items, restaurant tables and the opening-hours record.
 * (Named menu_* because the menu came first; it is the one emit path for all of
 * them — never copy the loop-guard/version logic into a second one.)
 *
 * Every owned write that should reach Zuri goes through here, so the data change
 * and its outbox event land in the SAME transaction — a price can never change
 * without its "this changed" event, and a rolled-back edit never leaves one.
 *
 *   menu_sync_tx(fn)                 run a write + its events atomically
 *   menu_sync_emit($entity,$id,$op)  bump sync_version and queue the event
 *   menu_delete_item/category/menu   soft delete (spec: never hard-delete a
 *                                    synced row — the peer would re-create it)
 *
 * Pre-migration (no sync columns) every function degrades to the old behaviour:
 * emit is a no-op and deletes are hard DELETEs, so the menu editor keeps working
 * on a database that has not run add_restaurant_sync.sql.
 *
 * The event is queued whether or not SYNC_ENABLED is on — per §10 outbox rows
 * accumulate while sync is off and drain in order when it comes back on. The
 * dispatcher is what the kill switch stops, never the write.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sync.php';
require_once __DIR__ . '/sync-mappers.php';

/**
 * entity => local table. `menu` is soft-deleted and versioned locally like the
 * others, but Zuri's contract has no menu entity, so it never emits an event.
 */
function menu_sync_tables(): array {
    return [
        'menu'             => 'menus',
        'menu_category'    => 'menu_categories',
        'menu_item'        => 'menu_items',
        'restaurant_table' => 'restaurant_tables',
        'opening_hours'    => 'restaurant_hours',   // ONE record per venue (contract)
    ];
}

/**
 * Run $fn and its outbox events in one transaction. Joins the caller's
 * transaction when there is one (PDO/pgsql cannot nest), else opens its own.
 */
function menu_sync_tx(callable $fn): mixed {
    if (db()->inTransaction()) return $fn();
    db()->beginTransaction();
    try {
        $out = $fn();
        db()->commit();
        return $out;
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
}

/**
 * Load one row for the mapper, WITH its parent's sync_uuid, regardless of
 * is_deleted (a delete event still describes the row). NULL when missing OR
 * outside the synced venue — only Zuri's own menu goes to Zuri.
 */
function menu_sync_row(string $entity, int $id): ?array {
    return sync_menu_rows($entity, $id)[0] ?? null;
}

/** The §3 envelope data for a loaded row — the SAME mappers the backfill export uses. */
function menu_sync_map(string $entity, array $row): array {
    return match ($entity) {
        'menu_category'    => sync_map_menu_category($row),
        'menu_item'        => sync_map_menu_item($row),
        'restaurant_table' => sync_map_restaurant_table($row),
        'opening_hours'    => sync_map_opening_hours($row),
    };
}

/**
 * Queue the outbox event for a row that was just written. Must be called inside
 * the same transaction as the write (use menu_sync_tx).
 *
 * Only rows of the synced venue (SYNC_VENUE_SLUG) are sent; the version is
 * bumped either way so it stays a true edit count.
 *
 * $op = create | update | delete. A create keeps the row's initial version (1,
 * the column default); update/delete bump it first, so the peer can order edits
 * per record (§2). Skipped entirely while applying an inbound change — that
 * change came FROM Zuri and must not echo back (loop prevention).
 */
function menu_sync_emit(string $entity, int $id, string $op): void {
    if ($id <= 0 || !sync_supported() || SyncContext::isApplying()) return;
    $table = menu_sync_tables()[$entity] ?? null;
    if ($table === null) return;

    if ($op !== 'create') {
        db_query(
            "UPDATE {$table} SET sync_version = sync_version + 1, sync_updated_at = now(),
                    sync_source = :src
              WHERE id = :id",
            [':src' => sync_self_source(), ':id' => $id]
        );
    }
    if ($entity === 'menu') return;                       // no such entity on Zuri
    $row = menu_sync_row($entity, $id);
    if (!$row) return;                                    // not the synced venue
    if ($entity === 'menu_item' && $op !== 'delete' && sync_menu_item_skip_reason($row) !== '') {
        return;   // unpriced — price is required; goes out on the first priced edit
    }

    sync_outbox_push(
        $entity, (string) $row['sync_uuid'], $op,
        menu_sync_map($entity, $row), (int) $row['sync_version'], $op === 'delete'
    );
}

/* ── Soft deletes ──────────────────────────────────────────────────────────
 * A deleted row stays in the table with is_deleted = TRUE and a `delete` event
 * goes to Zuri. Children are deleted (and announced) before their parent, so
 * Zuri never sees a category vanish while its items still point at it. Rows
 * already deleted are skipped — a repeat click never re-announces.
 * ───────────────────────────────────────────────────────────────────────── */

/** Mark one row deleted and announce it. Returns false if it was already gone. */
function menu_sync_soft_delete_row(string $entity, int $id, string $extraSet = ''): bool {
    $table = menu_sync_tables()[$entity];
    $done = db_query(
        "UPDATE {$table} SET is_deleted = TRUE{$extraSet} WHERE id = :id AND is_deleted = FALSE RETURNING id",
        [':id' => $id]
    )->fetchColumn();
    if (!$done) return false;
    menu_sync_emit($entity, $id, 'delete');
    return true;
}

function menu_delete_item(int $itemId): void {
    if (!sync_supported()) {
        db_query('DELETE FROM menu_items WHERE id = :i', [':i' => $itemId]);
        return;
    }
    menu_sync_tx(fn() => menu_sync_soft_delete_row('menu_item', $itemId));
}

function menu_delete_category(int $catId): void {
    if (!sync_supported()) {
        db_query('DELETE FROM menu_categories WHERE id = :c', [':c' => $catId]);   // cascade removes items
        return;
    }
    menu_sync_tx(function () use ($catId) {
        $items = db_query(
            'SELECT id FROM menu_items WHERE category_id = :c AND is_deleted = FALSE ORDER BY id',
            [':c' => $catId]
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($items as $iid) menu_sync_soft_delete_row('menu_item', (int) $iid);
        menu_sync_soft_delete_row('menu_category', $catId);
    });
}

/**
 * Soft-delete a whole menu. It is also unpublished (so every public reader,
 * which filters is_published, hides it without its own is_deleted check) and
 * its slug is freed (menus.slug is UNIQUE, so a deleted menu would otherwise
 * block a new menu from reusing the URL).
 */
function menu_delete_menu(int $menuId): void {
    if (!sync_supported()) {
        db_query('DELETE FROM menus WHERE id = :id', [':id' => $menuId]);   // cascade removes categories + items
        return;
    }
    menu_sync_tx(function () use ($menuId) {
        $cats = db_query(
            'SELECT id FROM menu_categories WHERE menu_id = :m AND is_deleted = FALSE ORDER BY id',
            [':m' => $menuId]
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($cats as $cid) {
            $items = db_query(
                'SELECT id FROM menu_items WHERE category_id = :c AND is_deleted = FALSE ORDER BY id',
                [':c' => (int) $cid]
            )->fetchAll(PDO::FETCH_COLUMN);
            foreach ($items as $iid) menu_sync_soft_delete_row('menu_item', (int) $iid);
            menu_sync_soft_delete_row('menu_category', (int) $cid);
        }
        menu_sync_soft_delete_row(
            'menu', $menuId,
            ", is_published = FALSE, updated_at = NOW(), slug = LEFT(slug, 100) || '--deleted-' || id"
        );
    });
}
