<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_once __DIR__ . '/../includes/staff-hold-guard.php';   // staff_hold_block_reason()

/**
 * Apply a "keep OTA" resolution: cancel the conflicting hold, block the dates
 * for the OTA, mark the conflict resolved.
 *
 * Returns ['ok'=>bool, 'error'=>string, 'cancelled_hold'=>?array]. The caller
 * sends the guest's cancellation e-mail for 'cancelled_hold' — deliberately NOT
 * sent from in here, see ORDER below.
 *
 * $hold is the conflicting hold row (or null); it is only acted on when it is
 * still pending/confirmed, exactly as before.
 *
 * ── WHY THIS IS GUARDED ────────────────────────────────────────────────────
 * The OTA block is written with components NULL, which mi_block_taken_components()
 * reads as the WHOLE unit. On a Maya Ilai villa that is eight bedrooms sold as
 * four components across eight villas, so "keep OTA" used to do this:
 *
 *     guest A holds {bunk} in villa 1, guest B holds {double_a} in villa 1
 *     staff resolve A's channel conflict in the OTA's favour
 *        -> A cancelled + e-mailed, then the WHOLE villa blocked for the OTA
 *        -> guest B is now booked into a villa an OTA guest has taken entire
 *
 * Staff consented to cancelling ONE bedroom's booking. Nothing on the page told
 * them about guest B. So the resolution now asks staff_hold_block_reason() — the
 * same frozen guard the two booking forms and the Gantt use — and refuses with a
 * message naming the villa, the dates and the sold components. Every unit that
 * is not a Maya Ilai villa gets NULL from the guard and is untouched.
 *
 * ── ORDER, AND THE SELF-EXCLUSION ──────────────────────────────────────────
 * The check has to come before the cancellation, not after: refusing after the
 * guest has been told their booking is gone is worse than either outcome.
 *
 * But the guard counts every overlapping block, including the one belonging to
 * the hold this resolution is about to cancel — which would refuse every villa
 * conflict, naming the very booking staff asked to drop. gantt_block_move()
 * parks a row to ask the guard a question in which it genuinely is not there;
 * here nothing needs parking, because DELETING that block is a step the
 * resolution performs anyway. Doing it first, inside the transaction, makes the
 * guard's answer a question about everybody ELSE. A refusal rolls the delete
 * back, so the hold is left exactly as it was.
 *
 * The transaction becomes a SAVEPOINT when the caller already owns one (PDO/pgsql
 * cannot nest, and tests/maya_ilai_inventory.php wraps its work in one it rolls
 * back) — same convention as gantt_block_move().
 *
 * The e-mail is the only step that cannot be rolled back, so it is not performed
 * here at all: the hold is handed back and the caller sends it after the commit.
 */
function conflict_keep_ota_apply(array $conflict, ?array $hold, ?int $adminId, string $notes): array
{
    $unitId = (int) $conflict['unit_id'];
    $from   = (string) $conflict['date_from'];
    $to     = (string) $conflict['date_to'];
    $cancel = $hold !== null && in_array($hold['status'], ['pending', 'confirmed'], true);

    $inTx = db()->inTransaction();
    $sp   = 'conflict_keep_ota';
    if ($inTx) db()->exec("SAVEPOINT {$sp}"); else db()->beginTransaction();

    try {
        // Self-exclusion, and the first real step of the resolution.
        if ($cancel) {
            db_query(
                "DELETE FROM availability_blocks WHERE hold_id=:hid",
                [':hid' => (int) $hold['id']]
            );
        }

        $reason = staff_hold_block_reason($unitId, $from, $to);
        if ($reason !== null) {
            if ($inTx) db()->exec("ROLLBACK TO SAVEPOINT {$sp}"); else db()->rollBack();
            return [
                'ok'             => false,
                'error'          => 'Not resolved — nothing was cancelled and no e-mail was sent. ' . $reason,
                'cancelled_hold' => null,
            ];
        }

        if ($cancel) {
            db_query(
                "UPDATE holds SET status='cancelled', cancelled_at=NOW() WHERE id=:id",
                [':id' => (int) $hold['id']]
            );
        }

        db_query(
            "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, notes)
             VALUES (:uid, :df, :dt, 'blocked', :notes)",
            [':uid'   => $unitId,
             ':df'    => $from,
             ':dt'    => $to,
             ':notes' => 'iCal (conflict resolved): ' . mb_substr((string) $conflict['ota_summary'], 0, 200)]
        );

        db_query(
            "UPDATE channel_conflicts
             SET status='resolved_keep_ota', resolved_at=NOW(), resolved_by=:uid, resolution_notes=:notes
             WHERE id=:id",
            [':uid' => $adminId, ':notes' => $notes, ':id' => (int) $conflict['id']]
        );

        if ($inTx) db()->exec("RELEASE SAVEPOINT {$sp}"); else db()->commit();
        return ['ok' => true, 'error' => '', 'cancelled_hold' => $cancel ? $hold : null];

    } catch (Throwable $e) {
        try {
            if ($inTx) db()->exec("ROLLBACK TO SAVEPOINT {$sp}");
            elseif (db()->inTransaction()) db()->rollBack();
        } catch (Throwable $undoFailed) {
            error_log('[conflict-keep-ota] undo failed: ' . $undoFailed->getMessage());
        }
        error_log('[conflict-keep-ota] ' . $e->getMessage());
        // Fail closed: half a resolution is worse than none, and the guest has
        // not been told anything yet.
        return [
            'ok'             => false,
            'error'          => 'Could not resolve the conflict. Nothing was changed — please reload and try again.',
            'cancelled_hold' => null,
        ];
    }
}

// tests/maya_ilai_inventory.php requires this file for conflict_keep_ota_apply().
if (defined('CONFLICTS_LIBRARY_ONLY')) return;

require_login();
require_bookings();

$success = '';
$error   = '';

// Property scope — '' for the owner, a venue clause for a scoped account
// (reception). Every read AND the resolve handler's own fetch use it, so a
// foreign conflict id simply resolves to "not found".
$cScope = venue_scope_sql('r.venue_id');

// ── POST: resolve a conflict ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $conflict_id = (int)($_POST['conflict_id'] ?? 0);
    $resolution  = $_POST['resolution'] ?? '';
    $notes       = trim($_POST['resolution_notes'] ?? '');
    $admin       = current_admin();

    if ($conflict_id && in_array($resolution, ['keep_hold', 'keep_ota'], true)) {
        $conflict = db_query(
            "SELECT c.*, u.name AS unit_name, r.name AS room_name, f.label AS feed_label
             FROM channel_conflicts c
             JOIN units u ON u.id = c.unit_id
             JOIN rooms r ON r.id = u.room_id
             LEFT JOIN ical_feeds f ON f.id = c.ical_feed_id
             WHERE c.id = :id AND c.status = 'pending'"
             . ($cScope !== '' ? " AND {$cScope}" : ''),
            [':id' => $conflict_id]
        )->fetch();

        if (!$conflict) {
            $error = 'Conflict not found or already resolved.';
        } elseif ($resolution === 'keep_hold') {
            // Mark resolved — OTA block was never inserted, hold stays
            db_query(
                "UPDATE channel_conflicts
                 SET status='resolved_keep_hold', resolved_at=NOW(), resolved_by=:uid, resolution_notes=:notes
                 WHERE id=:id",
                [':uid' => $admin['id'], ':notes' => $notes, ':id' => $conflict_id]
            );
            audit_log('conflict.keep_hold', 'channel_conflict', $conflict_id,
                "unit={$conflict['unit_name']} {$conflict['date_from']}→{$conflict['date_to']}");
            $success = "Conflict resolved — hold kept, OTA block discarded.";

        } elseif ($resolution === 'keep_ota') {
            // Cancel the hold, insert the OTA block
            $hold = null;
            if ($conflict['hold_id']) {
                $hold = db_query(
                    "SELECT h.*, u.name AS unit_name, r.name AS room_name
                     FROM holds h
                     JOIN units u ON u.id = h.unit_id
                     JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u') . "
                     WHERE h.id = :id",
                    [':id' => $conflict['hold_id']]
                )->fetch();
            }

            $applied = conflict_keep_ota_apply($conflict, $hold ?: null, (int)$admin['id'], $notes);

            if (!$applied['ok']) {
                $error = $applied['error'];
            } else {
                // The guest is told only once the resolution has actually been
                // written. An e-mail cannot be unsent, so it is the last step.
                if ($applied['cancelled_hold'] && $applied['cancelled_hold']['guest_email']) {
                    send_hold_cancelled($applied['cancelled_hold'], 'cancelled');
                }
                audit_log('conflict.keep_ota', 'channel_conflict', $conflict_id,
                    "hold #{$conflict['hold_id']} cancelled, OTA block inserted {$conflict['date_from']}→{$conflict['date_to']}");
                $success = "Conflict resolved — OTA block inserted, hold cancelled and guest notified.";
            }
        }
    } else {
        $error = 'Invalid request.';
    }
}

// ── Fetch conflicts ──────────────────────────────────────────────
$status_filter = $_GET['status'] ?? 'pending';
$where = match($status_filter) {
    'resolved' => "WHERE c.status != 'pending'",
    default    => "WHERE c.status = 'pending'",
};
if ($cScope !== '') $where .= " AND {$cScope}";

$conflicts = db_query(
    "SELECT c.*,
            u.name AS unit_name,
            r.name AS room_name,
            f.label AS feed_label,
            h.guest_name, h.guest_email, h.check_in AS hold_check_in, h.check_out AS hold_check_out,
            h.status AS hold_status,
            a.email AS resolved_by_email
     FROM channel_conflicts c
     JOIN units u ON u.id = c.unit_id
     JOIN rooms r ON r.id = u.room_id
     LEFT JOIN ical_feeds f ON f.id = c.ical_feed_id
     LEFT JOIN holds h ON h.id = c.hold_id
     LEFT JOIN admin_users a ON a.id = c.resolved_by
     $where
     ORDER BY c.created_at DESC
     LIMIT 200"
)->fetchAll();

$pending_count = (int)db_query(
    "SELECT COUNT(*) FROM channel_conflicts c
        JOIN units u ON u.id = c.unit_id
        JOIN rooms r ON r.id = u.room_id
      WHERE c.status='pending'" . ($cScope !== '' ? " AND {$cScope}" : '')
)->fetchColumn();

$pageTitle  = 'Channel Conflicts';
$activeMenu = 'conflicts';
include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Channel Conflicts</h1>
  <?php if ($pending_count > 0): ?>
  <span class="badge badge--red" style="font-size:13px"><?= $pending_count ?> pending</span>
  <?php endif; ?>
</div>

<p style="color:var(--muted);font-size:13px;margin-bottom:20px">
  A conflict occurs when an OTA iCal feed tries to block dates that already have a hold or booking.
  Resolve each conflict by choosing which takes priority.
</p>

<?php if ($success): ?><div class="alert alert--success"><?= e($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert--error"><?= e($error) ?></div><?php endif; ?>

<!-- Filter -->
<form method="GET" action="/admin/conflicts.php" class="filters" style="margin-bottom:20px">
  <label class="filter-field">Status
    <select name="status" class="filter-select" aria-label="Filter conflicts by status" onchange="this.form.submit()">
      <option value="pending"  <?= $status_filter === 'pending'  ? 'selected' : '' ?>>Pending (<?= $pending_count ?>)</option>
      <option value="resolved" <?= $status_filter === 'resolved' ? 'selected' : '' ?>>Resolved</option>
    </select>
  </label>
</form>

<?php if (!$conflicts): ?>
<div class="card">
  <div class="card__body"><?php dt_empty($status_filter === 'pending' ? 'No pending conflicts — all clear.' : 'No resolved conflicts yet.', 'check-check'); ?></div>
</div>
<?php else: ?>

<?php foreach ($conflicts as $c): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card__head" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <span class="card__title">
      <?= e($c['room_name']) ?> &middot; <?= e($c['unit_name']) ?>
      &mdash; <?= e($c['date_from']) ?> to <?= e($c['date_to']) ?>
    </span>
    <?php if ($c['status'] === 'pending'): ?>
    <span class="badge badge--red">Pending</span>
    <?php elseif ($c['status'] === 'resolved_keep_hold'): ?>
    <span class="badge badge--green">Resolved — hold kept</span>
    <?php else: ?>
    <span class="badge badge--orange">Resolved — OTA kept</span>
    <?php endif; ?>
  </div>
  <div class="card__body" style="padding:20px">
    <div class="detail-grid" style="margin-bottom:16px">
      <div>
        <div class="detail-item__label">OTA Source</div>
        <div class="detail-item__value"><?= e($c['feed_label'] ?? 'Unknown feed') ?></div>
      </div>
      <div>
        <div class="detail-item__label">OTA Event</div>
        <div class="detail-item__value"><?= e($c['ota_summary'] ?: '—') ?></div>
      </div>
      <div>
        <div class="detail-item__label">Conflicting Hold</div>
        <div class="detail-item__value">
          <?php if ($c['hold_id']): ?>
          #<?= e($c['hold_id']) ?> &middot; <?= e($c['guest_name'] ?? '—') ?> &middot; <?= e($c['guest_email'] ?? '') ?><br>
          <span style="font-size:12px;color:var(--muted)">
            <?= e($c['hold_check_in'] ?? '') ?> → <?= e($c['hold_check_out'] ?? '') ?>
            &middot; <span class="badge badge--<?= $c['hold_status'] === 'confirmed' ? 'green' : 'orange' ?>" style="font-size:10px"><?= e($c['hold_status'] ?? '') ?></span>
          </span>
          <?php else: ?>—<?php endif; ?>
        </div>
      </div>
      <div>
        <div class="detail-item__label">Detected</div>
        <div class="detail-item__value"><?= e(date('d M Y H:i', strtotime($c['created_at']))) ?></div>
      </div>
      <?php if ($c['status'] !== 'pending'): ?>
      <div>
        <div class="detail-item__label">Resolved by</div>
        <div class="detail-item__value"><?= e($c['resolved_by_email'] ?? '—') ?> on <?= e(date('d M Y H:i', strtotime($c['resolved_at'] ?? 'now'))) ?></div>
      </div>
      <?php if ($c['resolution_notes']): ?>
      <div>
        <div class="detail-item__label">Notes</div>
        <div class="detail-item__value"><?= e($c['resolution_notes']) ?></div>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>

    <?php if ($c['status'] === 'pending'): ?>
    <form method="POST" action="/admin/conflicts">
      <?= csrf_field() ?>
      <input type="hidden" name="conflict_id" value="<?= e($c['id']) ?>">
      <div class="field" style="max-width:480px;margin-bottom:12px">
        <label>Resolution notes <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
        <input type="text" name="resolution_notes" placeholder="e.g. Guest was moved to another room">
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <button type="submit" name="resolution" value="keep_hold" class="btn-primary"
                onclick="return confirm('Keep this hold and discard the OTA block?')">
          Keep Hold &mdash; discard OTA block
        </button>
        <button type="submit" name="resolution" value="keep_ota" class="btn-danger"
                onclick="return confirm('Cancel the hold and insert the OTA block? The guest will be notified.')">
          Keep OTA &mdash; cancel hold &amp; notify guest
        </button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
