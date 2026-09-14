<?php
declare(strict_types=1);
/**
 * Oversell guard for the THIRD staff-entered booking surface: the Gantt.
 *
 * includes/staff-hold-guard.php says it guards "the two staff-entered booking
 * forms" (admin/hold-new.php, admin/submission-view.php). There is a third, and
 * it is the one reception actually reaches for: admin/gantt.php writes
 * availability_blocks straight from the calendar — "Add block" (create_block)
 * and drag-to-move (update_block) — with no availability check at all.
 *
 * A Gantt block has no component picker, so it is written with components NULL,
 * which mi_block_taken_components() reads as the WHOLE villa. Dropped on a Maya
 * Ilai villa where a guest already bought one bedroom, it sells that bedroom
 * twice, violates no constraint and logs nothing:
 *
 *     guest books a Double Room 2098-04-01→05 -> block on villa 1, '{double_a}'
 *     reception adds a "booked" block on villa 1, same dates, from the Gantt
 *                                             -> block on villa 1, components NULL
 *
 * create_block calls staff_hold_block_reason() directly, exactly as
 * admin/submission-view.php does. Moving a block needs one thing that plain call
 * cannot express, which is why this file exists — see gantt_block_move().
 *
 * Everything here is a pass-through to the frozen guard, so every unit that is
 * not a Maya Ilai villa keeps the deliberate "you control overlaps" behaviour:
 * staff_hold_block_reason() returns NULL for them and nothing below runs.
 *
 * Test: php tests/maya_ilai_inventory.php
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/staff-hold-guard.php';

/**
 * Where a block is parked while the guard re-reads the villa without it.
 *
 * Any date outside every real booking window will do; it is only ever visible
 * inside the transaction/savepoint below, which always ends in a COMMIT of the
 * real move or a ROLLBACK. availability_blocks has no CHECK on its dates.
 */
const GANTT_BLOCK_PARK_FROM = '1900-01-01';
const GANTT_BLOCK_PARK_TO   = '1900-01-02';

/**
 * Move a calendar block, refusing a move that would oversell a Maya Ilai villa.
 *
 * Returns ['ok'=>bool, 'error'=>string]; the error is staff_hold_block_reason()'s
 * own message, which already names the villa, the dates and the sold components.
 *
 * THE SELF-OVERLAP PROBLEM. staff_hold_block_reason() answers "is this unit free
 * for these dates", counting every block that overlaps — including the very block
 * being dragged. Nudging a villa block one day later would refuse itself, and a
 * two-day drag inside its own span would refuse with "the whole villa is sold",
 * naming the mover. The guard is frozen and takes no exclusion, so the exclusion
 * is done here, by asking the guard a question in which the block genuinely is
 * not there:
 *
 *   1. Ask once, unchanged. NULL is conclusive — removing a block can only ever
 *      free components, never take more — so every non-Maya-Ilai move and every
 *      clean Maya Ilai move takes this path and behaves exactly as before: one
 *      guard read, then the same UPDATE, no transaction.
 *   2. Only on a refusal, and only when the block could actually be accusing
 *      itself (same destination unit, overlapping its current dates), PARK it on
 *      an impossible date range and ask again. A second refusal is somebody
 *      else's booking and is reported; a pass means the mover was the only thing
 *      in the way, so the real move is applied.
 *
 * The park-and-retry runs in a transaction (or a SAVEPOINT when the caller
 * already owns one — PDO/pgsql cannot nest, and the tests wrap their work in one
 * they roll back; same convention as create_hold_with_block()). Either the real
 * move commits or the parking is rolled back: the parked dates are never visible
 * to another request, and a refused move leaves the block exactly where it was.
 *
 * $unitScopeSql mirrors bookings_convert_block_to_hold(): a sub-select of the
 * acting account's unit ids, '' for the owner. It scopes BOTH the lookup and the
 * UPDATE, so a posted block id outside the account's venues is ignored — and, in
 * particular, a row this function would not move is never parked.
 */
function gantt_block_move(
    int $blockId, int $unitId, string $dateFrom, string $dateToExcl, string $unitScopeSql = ''
): array {
    $scope = $unitScopeSql !== '' ? " AND unit_id IN ({$unitScopeSql})" : '';

    // The row as it stands, under the same conditions the UPDATE will use. No
    // row means the UPDATE would match nothing either (wrong account, or a
    // block_type='hold' row, which is managed from holds.php) — a silent no-op,
    // exactly as before this guard existed.
    $row = db_query(
        "SELECT id, unit_id, date_from::text AS date_from, date_to::text AS date_to
           FROM availability_blocks
          WHERE id = :id AND block_type != 'hold'" . $scope,
        [':id' => $blockId]
    )->fetch();
    if (!$row) return ['ok' => true, 'error' => ''];

    $apply = static function () use ($blockId, $unitId, $dateFrom, $dateToExcl, $scope): void {
        db_query(
            "UPDATE availability_blocks
                SET unit_id = :uid, date_from = :df, date_to = :dt
              WHERE id = :id AND block_type != 'hold'" . $scope,
            [':uid' => $unitId, ':df' => $dateFrom, ':dt' => $dateToExcl, ':id' => $blockId]
        );
    };

    $reason = staff_hold_block_reason($unitId, $dateFrom, $dateToExcl);
    if ($reason === null) {            // (1) conclusive: nothing is in the way.
        $apply();
        return ['ok' => true, 'error' => ''];
    }

    // Could this block be the thing the guard is complaining about? Only if it
    // is already on the destination unit and its current span overlaps the
    // requested one (half-open: date_to is the checkout morning).
    $selfOverlaps = (int)$row['unit_id'] === $unitId
                 && (string)$row['date_from'] < $dateToExcl
                 && (string)$row['date_to']   > $dateFrom;
    if (!$selfOverlaps) return ['ok' => false, 'error' => $reason];

    // (2) Park it, ask again.
    $inTx = db()->inTransaction();
    $sp   = 'gantt_block_move';
    if ($inTx) db()->exec("SAVEPOINT {$sp}"); else db()->beginTransaction();
    try {
        db_query(
            "UPDATE availability_blocks SET date_from = :p1, date_to = :p2
              WHERE id = :id AND block_type != 'hold'" . $scope,
            [':p1' => GANTT_BLOCK_PARK_FROM, ':p2' => GANTT_BLOCK_PARK_TO, ':id' => $blockId]
        );
        $without = staff_hold_block_reason($unitId, $dateFrom, $dateToExcl);
        if ($without !== null) {       // Somebody else's booking. Put it back.
            if ($inTx) db()->exec("ROLLBACK TO SAVEPOINT {$sp}"); else db()->rollBack();
            return ['ok' => false, 'error' => $without];
        }
        $apply();
        if ($inTx) db()->exec("RELEASE SAVEPOINT {$sp}"); else db()->commit();
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        try {
            if ($inTx) db()->exec("ROLLBACK TO SAVEPOINT {$sp}");
            elseif (db()->inTransaction()) db()->rollBack();
        } catch (Throwable $undoFailed) {
            error_log('[gantt-move] undo failed: ' . $undoFailed->getMessage());
        }
        error_log('[gantt-move] ' . $e->getMessage());
        // Fail closed: the block stays where it is and the drag is reported as
        // refused, rather than a half-checked move being written.
        return ['ok' => false, 'error' => 'Could not move the block. Please reload the calendar and try again.'];
    }
}
