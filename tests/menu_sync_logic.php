<?php
declare(strict_types=1);
// Menu → outbox hooks + soft deletes (Zuri sync Stage 2).
// Run: php tests/menu_sync_logic.php
// Pure checks run anywhere; the DB round-trip runs in ONE rolled-back
// transaction when a DB with add_restaurant_sync.sql applied is reachable, else SKIPs.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/menu.php';
require_once __DIR__ . '/../includes/menu-sync.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Pure ────────────────────────────────────────────────────────────────────
check('owned entities are hooked',      array_keys(menu_sync_tables()) === ['menu', 'menu_category', 'menu_item', 'restaurant_table', 'opening_hours']);
check('entity → table map',             menu_sync_tables()['menu_item'] === 'menu_items');

$item = menu_sync_map('menu_item', [
    'category_sync_uuid' => 'cat-uuid', 'name' => 'Prawn Curry', 'description' => null, 'price' => '1450',
    'is_veg' => false, 'is_vegan' => false, 'is_spicy' => true, 'has_nuts' => false, 'has_gluten' => false,
    'is_gf' => true, 'is_signature' => false, 'is_available' => true, 'sort_order' => 3,
]);
check('emit uses the export mapper (price string)', $item['price'] === '1450.00');
check('emit links parent by sync_uuid',             $item['category_uuid'] === 'cat-uuid');

// ── DB round-trip (rolled back) ─────────────────────────────────────────────
if (!sync_supported() || !menus_supported() || menu_live_sql() === '') {
    echo "\nSKIP  DB assertions (add_restaurant_sync.sql not applied / DB unreachable)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"); exit($failures ? 1 : 0);
}

/** Outbox rows for one row's sync_uuid, oldest first. */
function outbox_for(string $table, int $id): array {
    $uuid = (string) db_query("SELECT sync_uuid FROM {$table} WHERE id = :id", [':id' => $id])->fetchColumn();
    return db_query('SELECT operation, version, payload FROM sync_outbox WHERE sync_uuid = :u ORDER BY id', [':u' => $uuid])->fetchAll();
}
/** The operation of the newest outbox event for one row ('' if none). */
function last_op(string $table, int $id): string {
    $rows = outbox_for($table, $id);
    return $rows ? (string) $rows[count($rows) - 1]['operation'] : '';
}

$zuriVenue  = (int) (db_query('SELECT id FROM venues WHERE slug = :s', [':s' => sync_venue_slug()])->fetchColumn() ?: 0);
$otherVenue = (int) (db_query('SELECT id FROM venues WHERE slug <> :s ORDER BY id LIMIT 1', [':s' => sync_venue_slug()])->fetchColumn() ?: 0);
if (!$zuriVenue || !$otherVenue) {
    echo "\nSKIP  DB assertions (need the '" . sync_venue_slug() . "' venue and one other)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"); exit($failures ? 1 : 0);
}

db()->beginTransaction();
try {
    $slug = 'tst-sync-' . bin2hex(random_bytes(3));
    db_query("INSERT INTO menus (venue_id, slug, title, is_published, sort_order) VALUES (:v, :s, 'Test Menu', TRUE, 999)", [':v' => $zuriVenue, ':s' => $slug]);
    $menuId = (int) db()->lastInsertId();
    menu_sync_emit('menu', $menuId, 'create');
    check('no event for the menu itself (not a Zuri entity)', last_op('menus', $menuId) === '');

    db_query("INSERT INTO menu_categories (menu_id, section, name, sort_order) VALUES (:m, 'food', 'Mains', 1)", [':m' => $menuId]);
    $catId = (int) db()->lastInsertId();
    menu_sync_emit('menu_category', $catId, 'create');

    db_query("INSERT INTO menu_items (category_id, name, price, sort_order) VALUES (:c, 'Prawn Curry', 1200, 1)", [':c' => $catId]);
    $itemId = (int) db()->lastInsertId();
    menu_sync_emit('menu_item', $itemId, 'create');

    $ob = outbox_for('menu_items', $itemId);
    check('create queues one event',         count($ob) === 1 && $ob[0]['operation'] === 'create');
    check('create keeps version 1',          (int) $ob[0]['version'] === 1);
    $p = json_decode($ob[0]['payload'], true);
    check('payload is a §3 envelope',        $p['entity'] === 'menu_item' && $p['source'] === 'tribalsand' && $p['deleted'] === false);
    check('payload never carries is_available', !array_key_exists('is_available', $p['data']));

    // Update bumps the version and queues a second event with the new price.
    db_query('UPDATE menu_items SET price = 1450 WHERE id = :i', [':i' => $itemId]);
    menu_sync_emit('menu_item', $itemId, 'update');
    $ob = outbox_for('menu_items', $itemId);
    $p  = json_decode($ob[1]['payload'], true);
    check('update queues a second event',    count($ob) === 2 && $ob[1]['operation'] === 'update');
    check('update bumps version to 2',       (int) $ob[1]['version'] === 2
                                             && (int) db_query('SELECT sync_version FROM menu_items WHERE id = :i', [':i' => $itemId])->fetchColumn() === 2);
    check('update carries the new price',    $p['data']['price'] === '1450.00');

    // Loop prevention: a write made while applying Zuri's change is never queued.
    SyncContext::applying(fn() => menu_sync_emit('menu_item', $itemId, 'update'));
    check('no event while applying',         count(outbox_for('menu_items', $itemId)) === 2);

    // A rolled-back edit leaves no event behind (menu_sync_tx joins this tx, so
    // use a savepoint to simulate the editor's own failed transaction).
    db()->exec('SAVEPOINT sp_fail');
    db_query('UPDATE menu_items SET price = 9999 WHERE id = :i', [':i' => $itemId]);
    menu_sync_emit('menu_item', $itemId, 'update');
    db()->exec('ROLLBACK TO SAVEPOINT sp_fail');
    check('rolled-back edit leaves no event', count(outbox_for('menu_items', $itemId)) === 2);

    // Soft delete: row survives, flagged, announced; reads hide it.
    menu_delete_item($itemId);
    $ob = outbox_for('menu_items', $itemId);
    $p  = json_decode(end($ob)['payload'], true);
    check('delete keeps the row',            (bool) db_query('SELECT 1 FROM menu_items WHERE id = :i', [':i' => $itemId])->fetchColumn());
    check('delete flags is_deleted',         db_query('SELECT is_deleted FROM menu_items WHERE id = :i', [':i' => $itemId])->fetchColumn() === true);
    check('delete queues a delete event',    end($ob)['operation'] === 'delete' && $p['deleted'] === true);
    $cats = fetch_menu_categories($menuId, true);
    check('admin read hides deleted item',   count($cats) === 1 && $cats[0]['items'] === []);
    menu_delete_item($itemId);
    check('repeat delete is not re-announced', count(outbox_for('menu_items', $itemId)) === count($ob));

    // Deleting the menu cascades: items → categories are announced; the menu itself is local-only.
    db_query("INSERT INTO menu_items (category_id, name, price, sort_order) VALUES (:c, 'Chapati', 100, 2)", [':c' => $catId]);
    $item2 = (int) db()->lastInsertId();
    menu_delete_menu($menuId);
    check('menu delete cascades to items',   last_op('menu_items', $item2) === 'delete');
    check('menu delete cascades to cats',    last_op('menu_categories', $catId) === 'delete');
    check('menu delete sends no menu event', last_op('menus', $menuId) === '');
    check('deleted menu is unpublished',     db_query('SELECT is_published FROM menus WHERE id = :m', [':m' => $menuId])->fetchColumn() === false);
    check('deleted menu frees its slug',     fetch_menu_by_slug($slug, false) === null
                                             && !db_query('SELECT 1 FROM menus WHERE slug = :s', [':s' => $slug])->fetchColumn());
    check('deleted menu hidden from admin',  fetch_menu($menuId) === null);

    // Another property's menu never reaches Zuri.
    db_query("INSERT INTO menus (venue_id, slug, title, is_published, sort_order) VALUES (:v, :s, 'Other', TRUE, 999)",
             [':v' => $otherVenue, ':s' => $slug . '-other']);
    $otherMenu = (int) db()->lastInsertId();
    db_query("INSERT INTO menu_categories (menu_id, section, name, sort_order) VALUES (:m, 'food', 'Breakfast', 1)", [':m' => $otherMenu]);
    $otherCat = (int) db()->lastInsertId();
    menu_sync_emit('menu_category', $otherCat, 'create');
    check('another venue\'s category is not sent', last_op('menu_categories', $otherCat) === '');

    // An unpriced item waits; its first priced edit goes out.
    db_query("INSERT INTO menu_categories (menu_id, section, name, sort_order) VALUES (:m, 'food', 'Specials', 2)", [':m' => $menuId]);
    $cat2 = (int) db()->lastInsertId();
    db_query("INSERT INTO menu_items (category_id, name, price, sort_order) VALUES (:c, 'Catch of the day', NULL, 1)", [':c' => $cat2]);
    $mp = (int) db()->lastInsertId();
    menu_sync_emit('menu_item', $mp, 'create');
    check('unpriced item is held back',      last_op('menu_items', $mp) === '');
    db_query('UPDATE menu_items SET price = 2500 WHERE id = :i', [':i' => $mp]);
    menu_sync_emit('menu_item', $mp, 'update');
    check('first priced edit is sent',       last_op('menu_items', $mp) === 'update');

    db()->rollBack();
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    echo 'ERROR ' . $e->getMessage() . "\n";
    $failures++;
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
