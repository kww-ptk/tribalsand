#!/usr/bin/env php
<?php
/**
 * Phase A reconciliation — fix the prod capacity/unit data the audit
 * (bin/audit-capacity-units.php) found wrong, SAFELY.
 *
 * Root cause (confirmed by the prod audit 2026-09-10): every room carries exactly
 * ONE extra active unit — the `add_availability` migration's "seed one default
 * unit per room" ran on top of the by-room seed. So single-room suites show 2
 * active units (should be 1) and the Maya Ilai villa/studio show 9 (should be 8).
 * search does free_units × capacity, so the stray unit is the whole "2× a
 * one-room suite" bug. Two whole-property buyouts also have a NULL capacity.
 *
 * What this does:
 *   1. Sets the two NULL buyout capacities (maya-kobe-buyout=16, sandbox=8).
 *   2. Reduces each listed room to its target active-unit count by DEACTIVATING
 *      (never deleting) the surplus — but ONLY units with no future booking, so a
 *      live hold/block is never stranded. A room it can't safely reduce (because
 *      too many booked units) is REPORTED, not touched.
 *
 * SAFE BY DEFAULT: dry-run unless --apply is passed. Idempotent — re-running
 * after --apply changes nothing. Run it on PROD via an ECS run-task command
 * override, exactly like the audit:
 *     php bin/reconcile-capacity-units.php              # preview (writes nothing)
 *     php bin/reconcile-capacity-units.php --apply      # do it
 * Then re-run bin/audit-capacity-units.php — the goal is zero anomalies.
 */
declare(strict_types=1);
chdir(dirname(__DIR__));
require_once __DIR__ . '/../includes/db.php';

$apply = in_array('--apply', $argv, true);

// Target ACTIVE-unit count per room (from the audit + source-of-truth). Rooms not
// listed here are left untouched. Whole-property + single suites → 1; the two
// Maya Ilai multi-unit room types → 8.
$TARGETS = [
    'my-amani-full-rental' => 1,
    'maya-kobe-prestige'   => 1,
    'maya-kobe-haze'       => 1,
    'maya-kobe-glow'       => 1,
    'maya-kobe-tide'       => 1,
    'maya-kobe-drift'      => 1,
    'zuri-bahari'          => 1,
    'zuri-maji'            => 1,
    'zuri-mwezi'           => 1,
    'zuri-ua'              => 1,
    'zuri-anga'            => 1,
    'zuri-jua'             => 1,
    'zuri-buyout'          => 1,
    'enkare-bofa'          => 1,
    'sandbox'              => 1,
    'superior-suite'       => 1,
    'maya-ilai-villa'      => 8,
    'maya-ilai-studio'     => 8,
];

// Capacities to set only where currently NULL/0 (the two buyouts the audit flagged).
$CAP_FIX = ['maya-kobe-buyout' => 16, 'sandbox' => 8];

echo "Tribal Sand — capacity & unit reconciliation  (" . date('Y-m-d H:i:s') . ")\n";
echo $apply ? "MODE: APPLY (writing changes)\n" : "MODE: DRY-RUN (no changes — pass --apply to write)\n";
echo str_repeat('=', 92) . "\n\n";

/** True if a unit has any future-facing block or live hold (→ must be kept). */
function unit_is_booked(int $unitId): bool {
    $today = date('Y-m-d');
    $blk = (int) db_query(
        "SELECT COUNT(*) FROM availability_blocks WHERE unit_id = :u AND date_to > :t",
        [':u' => $unitId, ':t' => $today]
    )->fetchColumn();
    if ($blk > 0) return true;
    $hold = (int) db_query(
        "SELECT COUNT(*) FROM holds WHERE unit_id = :u AND status IN ('pending','confirmed') AND check_out > :t",
        [':u' => $unitId, ':t' => $today]
    )->fetchColumn();
    return $hold > 0;
}

if ($apply) db()->beginTransaction();
try {
    // ── 1. Capacities ────────────────────────────────────────────────────────
    echo "CAPACITIES (set only where NULL/0)\n" . str_repeat('-', 92) . "\n";
    foreach ($CAP_FIX as $slug => $cap) {
        $row = db_query('SELECT id, capacity FROM rooms WHERE slug = :s', [':s' => $slug])->fetch();
        if (!$row) { echo "  ! {$slug}: not found — skipped\n"; continue; }
        $cur = $row['capacity'];
        if ($cur === null || (int)$cur === 0) {
            echo "  • {$slug}: capacity " . ($cur === null ? 'NULL' : '0') . " → {$cap}" . ($apply ? '  [set]' : '  [would set]') . "\n";
            if ($apply) db_query('UPDATE rooms SET capacity = :c, updated_at = NOW() WHERE id = :id', [':c' => $cap, ':id' => (int)$row['id']]);
        } else {
            echo "  · {$slug}: capacity already {$cur} — left as-is\n";
        }
    }
    echo "\n";

    // ── 2. Units ─────────────────────────────────────────────────────────────
    echo "UNITS (deactivate booking-free surplus down to target; keep booked units)\n" . str_repeat('-', 92) . "\n";
    $deactivated = 0; $conflicts = [];
    foreach ($TARGETS as $slug => $target) {
        $room = db_query('SELECT id FROM rooms WHERE slug = :s', [':s' => $slug])->fetch();
        if (!$room) { echo "  ! {$slug}: not found — skipped\n"; continue; }
        $units = db_query(
            'SELECT id FROM units WHERE room_id = :r AND is_active = TRUE ORDER BY id ASC',
            [':r' => (int)$room['id']]
        )->fetchAll(PDO::FETCH_COLUMN);
        $active = count($units);
        if ($active <= $target) { echo "  · {$slug}: {$active} active (target {$target}) — ok\n"; continue; }

        // Split into booked (must keep) and free (candidates to deactivate).
        $booked = []; $free = [];
        foreach ($units as $uid) {
            if (unit_is_booked((int)$uid)) $booked[] = (int)$uid; else $free[] = (int)$uid;
        }

        // Keep = all booked + enough of the oldest free ones to reach target.
        $keepFree = max(0, $target - count($booked));
        $freeKept = array_slice($free, 0, $keepFree);
        $toDeact  = array_values(array_diff($free, $freeKept));   // free surplus only

        $resultActive = count($booked) + count($freeKept);
        if (count($booked) > $target) {
            $conflicts[] = "{$slug}: {$active} active, but " . count($booked) . " have future bookings (target {$target}) — resolve manually";
            echo "  ⚠ {$slug}: {$active} active — " . count($booked) . " BOOKED > target {$target}; deactivating " . count($toDeact) . " free, still leaves " . $resultActive . " — MANUAL REVIEW\n";
        } else {
            echo "  • {$slug}: {$active} → {$target}  (keep " . count($booked) . " booked + " . count($freeKept) . " free; deactivate " . count($toDeact) . ": [" . implode(',', $toDeact) . "])" . ($apply ? '  [done]' : '  [would do]') . "\n";
        }
        foreach ($toDeact as $uid) {
            if ($apply) db_query('UPDATE units SET is_active = FALSE WHERE id = :id', [':id' => $uid]);
            $deactivated++;
        }
    }

    if ($apply) db()->commit();

    echo "\n" . str_repeat('=', 92) . "\n";
    echo ($apply ? "APPLIED. " : "DRY-RUN. ") . "Units " . ($apply ? 'deactivated' : 'to deactivate') . ": {$deactivated}.\n";
    if ($conflicts) {
        echo "\nMANUAL REVIEW — rooms with more booked units than target:\n";
        foreach ($conflicts as $c) echo "  - {$c}\n";
    }
    echo $apply
        ? "\nRe-run bin/audit-capacity-units.php to confirm zero anomalies.\n"
        : "\nNothing was changed. Re-run with --apply once the plan above looks right.\n";
} catch (\Throwable $e) {
    if ($apply && db()->inTransaction()) db()->rollBack();
    fwrite(STDERR, "\nFAILED (rolled back): " . $e->getMessage() . "\n");
    exit(1);
}
