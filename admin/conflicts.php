<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mail.php';
require_login();

$success = '';
$error   = '';

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
             WHERE c.id = :id AND c.status = 'pending'",
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
                     JOIN rooms r ON r.id = u.room_id
                     WHERE h.id = :id",
                    [':id' => $conflict['hold_id']]
                )->fetch();
            }

            if ($hold && in_array($hold['status'], ['pending', 'confirmed'], true)) {
                db_query(
                    "UPDATE holds SET status='cancelled', cancelled_at=NOW() WHERE id=:id",
                    [':id' => $hold['id']]
                );
                db_query(
                    "DELETE FROM availability_blocks WHERE hold_id=:hid",
                    [':hid' => $hold['id']]
                );
                if ($hold['guest_email']) send_hold_cancelled($hold, 'cancelled');
            }

            db_query(
                "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, notes)
                 VALUES (:uid, :df, :dt, 'blocked', :notes)",
                [':uid'   => $conflict['unit_id'],
                 ':df'    => $conflict['date_from'],
                 ':dt'    => $conflict['date_to'],
                 ':notes' => 'iCal (conflict resolved): ' . mb_substr($conflict['ota_summary'], 0, 200)]
            );

            db_query(
                "UPDATE channel_conflicts
                 SET status='resolved_keep_ota', resolved_at=NOW(), resolved_by=:uid, resolution_notes=:notes
                 WHERE id=:id",
                [':uid' => $admin['id'], ':notes' => $notes, ':id' => $conflict_id]
            );
            audit_log('conflict.keep_ota', 'channel_conflict', $conflict_id,
                "hold #{$conflict['hold_id']} cancelled, OTA block inserted {$conflict['date_from']}→{$conflict['date_to']}");
            $success = "Conflict resolved — OTA block inserted, hold cancelled and guest notified.";
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
    "SELECT COUNT(*) FROM channel_conflicts WHERE status='pending'"
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
<form method="GET" action="/admin/conflicts.php" style="margin-bottom:20px">
  <select name="status" onchange="this.form.submit()" style="height:36px;padding:0 10px;border:1px solid var(--border);border-radius:5px;font-size:13px">
    <option value="pending"  <?= $status_filter === 'pending'  ? 'selected' : '' ?>>Pending (<?= $pending_count ?>)</option>
    <option value="resolved" <?= $status_filter === 'resolved' ? 'selected' : '' ?>>Resolved</option>
  </select>
</form>

<?php if (!$conflicts): ?>
<div class="card">
  <div class="card__body" style="padding:32px;text-align:center;color:var(--muted)">
    <?= $status_filter === 'pending' ? 'No pending conflicts — all clear.' : 'No resolved conflicts yet.' ?>
  </div>
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
    <form method="POST" action="/admin/conflicts.php">
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
