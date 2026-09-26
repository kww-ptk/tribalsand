<?php
declare(strict_types=1);
/**
 * POS pre-migration guards — kept apart from includes/pos.php so light callers
 * (the admin sidebar on every page, the Bill tab) can ask "is the POS installed?"
 * without loading the whole POS library. Both are catalog lookups, never a
 * failing SELECT: they may run inside a transaction, and in Postgres a failed
 * statement aborts the whole transaction.
 */

require_once __DIR__ . '/db.php';

/** True once add_pos.sql has run. Catalog lookup — safe inside a transaction. */
function pos_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $ok = (bool) db_query("SELECT to_regclass('public.pos_sales') IS NOT NULL")->fetchColumn(); }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** True once bill_items.pos_sale_id exists (same migration; probed separately for the Bill tab). */
function pos_bill_link_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool) db_query(
            "SELECT 1 FROM information_schema.columns
              WHERE table_schema = current_schema() AND table_name = 'bill_items' AND column_name = 'pos_sale_id'"
        )->fetchColumn();
    } catch (Throwable $e) { $ok = false; }
    return $ok;
}


/**
 * True when this admin account can sell at the till: owner and managers always
 * (the till shows them their outlets, possibly none), everyone else only once
 * assigned to an outlet. Cheap — gates the sidebar "Open till" link.
 */
function pos_is_seller(array $admin): bool {
    if (!pos_supported()) return false;
    if (in_array($admin['role'] ?? '', ['owner', 'manager'], true)) return true;
    try {
        return (bool) db_query('SELECT 1 FROM pos_outlet_staff s JOIN pos_outlets o ON o.id = s.outlet_id AND o.is_active = TRUE
                                 WHERE s.admin_user_id = :u LIMIT 1', [':u' => (int)($admin['id'] ?? 0)])->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/**
 * True once add_pos_v2.sql has run (VAT, tips, room-charge properties, FX,
 * signatures, per-item consignment terms). Catalog lookup — transaction-safe.
 * Everything v2 degrades to the v1 behaviour until then.
 */
function pos_v2_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool) db_query(
            "SELECT 1 FROM information_schema.columns
              WHERE table_schema = current_schema() AND table_name = 'pos_sales' AND column_name = 'tip_amount'"
        )->fetchColumn();
    } catch (Throwable $e) { $ok = false; }
    return $ok;
}
