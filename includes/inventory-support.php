<?php
declare(strict_types=1);
/**
 * Inventory — pre-migration guard, the refusal type and the ONE transaction helper.
 * Kept apart from includes/inventory.php so light callers (the admin sidebar, the
 * POS) can ask "is inventory installed?" without loading the library.
 *
 * inv_supported() is a catalog lookup, never a failing SELECT: it runs inside
 * transactions, and in Postgres a failed statement aborts the whole transaction.
 */

require_once __DIR__ . '/db.php';

/** A refusal the caller shows to the user (not enough stock, closed location…). */
class InvRefusal extends RuntimeException {}

/** True once add_inventory.sql has run. */
function inv_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $ok = (bool) db_query("SELECT to_regclass('public.inv_moves') IS NOT NULL")->fetchColumn(); }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

/**
 * Run $fn atomically. Opens a transaction when none is open; inside an existing
 * one (tests wrap everything in a rolled-back transaction, the POS sale wraps its
 * stock moves) it uses a SAVEPOINT, so a refusal discards its own partial writes
 * without aborting the caller's transaction. pos_tx() delegates here — there is
 * one savepoint counter for the whole request.
 */
function inv_tx(callable $fn): mixed {
    static $depth = 0;
    $pdo = db();
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        try { $r = $fn(); $pdo->commit(); return $r; }
        catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
    $sp = 'inv_sp_' . (++$depth);
    $pdo->exec("SAVEPOINT {$sp}");
    try { $r = $fn(); $pdo->exec("RELEASE SAVEPOINT {$sp}"); $depth--; return $r; }
    catch (Throwable $e) {
        try { $pdo->exec("ROLLBACK TO SAVEPOINT {$sp}"); $pdo->exec("RELEASE SAVEPOINT {$sp}"); } catch (Throwable $ignored) {}
        $depth--;
        throw $e;
    }
}
