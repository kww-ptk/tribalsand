<?php
declare(strict_types=1);
/**
 * Group allocation import — a group's room list (CSV) → one confirmed booking per
 * room, then the guest links to send. Logic: includes/group-import.php. Spec:
 * docs/superpowers/specs/2026-10-09-group-allocation-import-design.md.
 *
 * Owner + house manager, scoped by admin_venue_ids(). Upload → preview (writes
 * nothing; non-Maya-Ilai room labels are mapped to a room here) → Create (one
 * transaction) → links page (?batch=<slug>, CSV with &export=csv). No emails.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/group-import.php';
require_login();
require_manager();

if (session_status() === PHP_SESSION_NONE) session_start();
$scope = admin_venue_ids();   // null = owner (all)
$self  = '/admin/import-group.php';
$error = '';

/** Hold ids of a batch that this account may see (managers: their properties only). */
$scopedIds = function (array $ids) use ($scope): array {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids || $scope === null) return $ids;
    if (!$scope) return [];
    return array_map('intval', db_query(
        'SELECT h.id FROM holds h JOIN units u ON u.id = h.unit_id JOIN rooms r ON r.id = u.room_id
          WHERE h.id IN (' . implode(',', $ids) . ') AND r.venue_id IN (' . implode(',', array_map('intval', $scope)) . ')'
    )->fetchAll(PDO::FETCH_COLUMN));
};

// ── CSV export of a batch's links ───────────────────────────────────────────
if (isset($_GET['batch'], $_GET['export']) && ($b = gi_batch((string)$_GET['batch']))) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $b['slug'] . '-links.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Head / contact email', 'Property', 'Room', 'Booking name', 'Check-in', 'Check-out', 'Status', 'Guest link'], ',', '"', '');
    foreach (gi_batch_links($scopedIds($b['hold_ids']), $b['heads']) as $g) {
        foreach ($g['rooms'] as $r) {
            fputcsv($out, [$g['email'], $r['property'], $r['room'], $r['guest'], $r['check_in'], $r['check_out'], $r['status'], $r['link']], ',', '"', '');
        }
    }
    exit;
}

// ── POST ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'cancel') {
        unset($_SESSION['group_import']);
        header('Location: ' . $self); exit;
    }

    if ($action === 'preview') {
        $f = $_FILES['sheet'] ?? null;
        $label = trim((string)($_POST['label'] ?? ''));
        if ($label === '') $error = 'Give the group a name (e.g. "Chris & Bini wedding").';
        elseif (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) $error = 'Choose the CSV file.';
        elseif (($f['size'] ?? 0) > 2 * 1024 * 1024) $error = 'The file is larger than 2 MB.';
        else {
            $parsed = gi_parse_csv((string)file_get_contents($f['tmp_name']));
            if (!$parsed['rows']) $error = $parsed['errors'][0] ?? 'No rows found in the file.';
            else {
                $_SESSION['group_import'] = ['label' => mb_substr($label, 0, 120), 'filename' => (string)($f['name'] ?? ''),
                                             'rows' => $parsed['rows'], 'notes' => $parsed['errors']];
                header('Location: ' . $self . '?step=preview'); exit;
            }
        }
    }

    if ($action === 'map' && !empty($_SESSION['group_import'])) {
        // Only rooms of a property this account may act on, and only that property's own rooms.
        $map = gi_room_map();
        foreach ((array)($_POST['map'] ?? []) as $vid => $labels) {
            $vid = (int)$vid;
            if ($scope !== null && !in_array($vid, array_map('intval', $scope), true)) continue;
            foreach ((array)$labels as $lab => $rid) {
                $rid = (int)$rid;
                if ($rid > 0) {
                    $ok = db_query('SELECT 1 FROM rooms WHERE id = :r AND venue_id = :v', [':r' => $rid, ':v' => $vid])->fetchColumn();
                    if ($ok) $map[(string)$vid][(string)$lab] = $rid;
                } else {
                    unset($map[(string)$vid][(string)$lab]);
                }
            }
        }
        set_setting(GI_ROOM_MAP_SETTING, json_encode($map, JSON_UNESCAPED_UNICODE));
        header('Location: ' . $self . '?step=preview&mapped=1'); exit;
    }

    if ($action === 'create' && !empty($_SESSION['group_import'])) {
        $gi   = $_SESSION['group_import'];
        $plan = gi_plan($gi['rows'], gi_room_map(), $scope);
        try {
            $res = gi_create($plan, (string)$gi['label'], (int)($_SESSION['admin_id'] ?? 0));
            unset($_SESSION['group_import']);
            header('Location: ' . $self . '?batch=' . urlencode($res['slug']) . '&created=' . $res['created']); exit;
        } catch (Throwable $e) {
            error_log('[import-group] create failed: ' . $e->getMessage());
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Nothing was created — the import failed. Please try again.';
        }
    }
}

$step  = (string)($_GET['step'] ?? '');
$gi    = $_SESSION['group_import'] ?? null;
$batch = isset($_GET['batch']) ? gi_batch((string)$_GET['batch']) : null;

$pageTitle  = 'Group import';
$activeMenu = 'import_bookings';
include __DIR__ . '/_layout.php';

$badge = function (string $status): string {
    $m = ['ready' => ['green', 'Will create'], 'imported' => ['teal', 'Already imported'], 'taken' => ['red', 'Not free'],
          'skipped' => ['grey', 'Skipped'], 'unmapped' => ['orange', 'Choose room'], 'scope' => ['grey', 'Not yours'],
          'error' => ['red', 'Problem']];
    [$c, $l] = $m[$status] ?? ['grey', $status];
    return '<span class="badge badge--' . $c . '">' . e($l) . '</span>';
};
$fmtD = fn(?string $d) => $d ? date('j M', strtotime($d)) : '—';
?>
<style>
.gi-table td{vertical-align:top}
.gi-names{font-size:12.5px;color:var(--muted,#6b7280);margin-top:2px}
.gi-reason{font-size:12px;color:var(--muted,#6b7280);margin-top:4px}
.gi-head{display:flex;flex-wrap:wrap;gap:10px 16px;align-items:flex-start;justify-content:space-between;padding:16px 18px;border-top:1px solid var(--border,#e5e7eb)}
.gi-head:first-child{border-top:0}
.gi-head__who{min-width:0;flex:1 1 320px}
.gi-head__name{font-weight:600}
.gi-head__email{font-size:12.5px;color:var(--muted,#6b7280);overflow-wrap:anywhere}
.gi-rooms{margin:8px 0 0;padding:0;list-style:none;display:grid;gap:6px}
.gi-rooms li{font-size:13px;line-height:1.45}
.gi-rooms a{overflow-wrap:anywhere}
.gi-btns{display:flex;gap:8px;flex-wrap:wrap;flex:0 0 auto}
.gi-wa{background:#25d366;border-color:#25d366;color:#fff}
.gi-wa:hover{background:#1ebe5a;color:#fff}
.gi-map{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:10px 16px;align-items:center;max-width:760px}
@media (max-width:640px){.gi-map{grid-template-columns:minmax(0,1fr)}}
</style>

<div class="page-header">
  <h1 style="display:inline-flex;align-items:center;gap:10px"><?= admin_icon('users', 22) ?> Group import</h1>
</div>
<?php if ($error): ?><div class="alert alert--error is-flash"><?= e($error) ?></div><?php endif; ?>

<?php if ($batch): ?>
  <?php
    $groups  = gi_batch_links($scopedIds($batch['hold_ids']), $batch['heads']);
    $rooms   = array_sum(array_map(fn($g) => count($g['rooms']), $groups));
    $created = isset($_GET['created']) ? (int)$_GET['created'] : null;
  ?>
  <?php if ($created !== null): ?><div class="alert alert--success is-flash"><?= $created ?> booking<?= $created === 1 ? '' : 's' ?> created. No emails were sent — send the links below.</div><?php endif; ?>
  <div class="card">
    <div class="card__head">
      <span class="card__title"><?= e($batch['label']) ?> · <?= $rooms ?> room<?= $rooms === 1 ? '' : 's' ?> · <?= count($groups) ?> contact<?= count($groups) === 1 ? '' : 's' ?></span>
      <a class="btn-outline btn-sm" href="<?= e($self . '?batch=' . urlencode($batch['slug']) . '&export=csv') ?>" data-no-shell><?= admin_icon('download', 14) ?> CSV of all links</a>
    </div>
    <div class="card__body" style="padding:0">
      <?php if (!$groups): ?><p class="text-muted" style="padding:18px;margin:0">No bookings in this group that you can see.</p><?php endif; ?>
      <?php foreach ($groups as $g):
            $text = gi_share_text($g['head'], $g['rooms'], (string)$batch['label']);
            $mail = 'mailto:' . rawurlencode($g['email']) . '?subject=' . rawurlencode('Your booking link' . (count($g['rooms']) > 1 ? 's' : '') . ' — ' . $batch['label']) . '&body=' . rawurlencode($text); ?>
      <div class="gi-head">
        <div class="gi-head__who">
          <div class="gi-head__name"><?= e($g['head']) ?></div>
          <div class="gi-head__email"><?= e($g['email']) ?></div>
          <ul class="gi-rooms">
            <?php foreach ($g['rooms'] as $r): ?>
            <li><strong><?= e($r['property']) ?> · <?= e($r['room']) ?></strong> — <?= e($r['guest']) ?> · <?= e($fmtD($r['check_in'])) ?> → <?= e($fmtD($r['check_out'])) ?>
              <?php if ($r['status'] === 'cancelled'): ?> <span class="badge badge--grey">Cancelled</span><?php endif; ?><br>
              <?php if ($r['link'] !== ''): ?><a href="<?= e($r['link']) ?>" target="_blank" rel="noopener" data-no-shell><?= e($r['link']) ?></a>
              <?php else: ?><span class="text-muted">No link — BOOKING_TOKEN_SECRET is not set.</span><?php endif; ?>
              · <a href="/admin/booking.php?id=<?= (int)$r['hold_id'] ?>">Open booking</a></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <div class="gi-btns">
          <a class="btn-sm btn-outline gi-wa" href="<?= e('https://wa.me/?text=' . rawurlencode($text)) ?>" target="_blank" rel="noopener" data-no-shell>WhatsApp</a>
          <a class="btn-sm btn-outline" href="<?= e($mail) ?>" data-no-shell><?= admin_icon('mail', 14) ?> Email</a>
          <button type="button" class="btn-sm btn-outline" data-gi-copy="<?= e($text) ?>"><?= admin_icon('copy', 14) ?> Copy</button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <p style="margin-top:14px"><a href="<?= $self ?>">&larr; Group import</a></p>

<?php elseif ($step === 'preview' && $gi): ?>
  <?php
    $plan   = gi_plan($gi['rows'], gi_room_map(), $scope);
    $counts = array_count_values(array_column($plan, 'status'));
    $ready  = (int)($counts['ready'] ?? 0);
    // Room labels to map: rows at a property that isn't Maya Ilai (which reads its labels itself).
    $toMap = [];
    foreach ($plan as $p) {
        if (!$p['venue_id'] || in_array($p['status'], ['scope', 'skipped'], true)) continue;
        if (gi_resolve_label((string)db_query('SELECT slug FROM venues WHERE id = :v', [':v' => $p['venue_id']])->fetchColumn(), (string)$p['room']) !== null) continue;
        $toMap[$p['venue_id']]['name'] = $p['venue_name'];
        $toMap[$p['venue_id']]['labels'][(string)$p['room']] = true;
    }
    $saved = gi_room_map();
  ?>
  <?php if (isset($_GET['mapped'])): ?><div class="alert alert--success is-flash">Room choices saved.</div><?php endif; ?>
  <?php foreach ((array)($gi['notes'] ?? []) as $n): ?><div class="alert alert--error"><?= e($n) ?></div><?php endforeach; ?>

  <?php if ($toMap): ?>
  <div class="card" style="margin-bottom:16px">
    <div class="card__head"><span class="card__title"><?= admin_icon('link', 16) ?> Match the room names to your rooms</span></div>
    <div class="card__body" style="padding:18px">
      <form method="POST">
        <?= csrf_field() ?><input type="hidden" name="action" value="map">
        <?php foreach ($toMap as $vid => $m):
              $vrooms = db_query('SELECT id, name FROM rooms WHERE venue_id = :v ORDER BY sort_order, name', [':v' => $vid])->fetchAll(); ?>
        <p style="margin:0 0 8px;font-weight:600"><?= e($m['name']) ?></p>
        <div class="gi-map" style="margin-bottom:16px">
          <?php foreach (array_keys($m['labels']) as $lab): $cur = (int)($saved[(string)$vid][$lab] ?? 0); ?>
          <span><?= e($lab) ?></span>
          <select name="map[<?= (int)$vid ?>][<?= e($lab) ?>]">
            <option value="0">— Choose a room —</option>
            <?php foreach ($vrooms as $vr): ?><option value="<?= (int)$vr['id'] ?>"<?= $cur === (int)$vr['id'] ? ' selected' : '' ?>><?= e($vr['name']) ?></option><?php endforeach; ?>
          </select>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <button type="submit" class="btn-outline"><?= admin_icon('check', 15) ?> Save room choices</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card__head"><span class="card__title"><?= admin_icon('eye', 16) ?> Preview — <?= e($gi['label']) ?> <span class="text-muted" style="font-weight:400">(<?= e($gi['filename']) ?>)</span></span></div>
    <div class="card__body" style="padding:18px">
      <p style="margin:0 0 12px"><strong><?= $ready ?></strong> booking<?= $ready === 1 ? '' : 's' ?> will be created.
        <span class="text-muted"><?= (int)($counts['imported'] ?? 0) ?> already imported · <?= (int)($counts['taken'] ?? 0) ?> not free · <?= (int)($counts['unmapped'] ?? 0) ?> need a room · <?= (int)($counts['skipped'] ?? 0) ?> skipped<?= !empty($counts['error']) ? ' · ' . (int)$counts['error'] . ' with a problem' : '' ?></span></p>
      <p class="text-muted" style="margin:0 0 14px;font-size:12.5px;max-width:80ch">Each room becomes a confirmed booking with no price and no email. The booking carries the head's email; its name is the head if they're in that room, otherwise the first guest. Everyone listed is pre-filled into the room's check-in. To change a name or date, fix the file and upload it again.</p>
      <div class="table-wrap" style="overflow-x:auto">
        <table class="data-table gi-table" style="width:100%">
          <thead><tr><th>#</th><th>Property · room</th><th>Books</th><th>Guests</th><th>Contact</th><th>Dates</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($plan as $p): ?>
            <tr>
              <td class="text-muted"><?= (int)$p['line'] ?></td>
              <td><?= e($p['venue_name'] ?: $p['property']) ?><div class="gi-names"><?= e($p['room']) ?></div></td>
              <td><?= e($p['target'] ?: '—') ?></td>
              <?php if (!$p['names']): ?><td class="text-muted">Empty room</td><?php else: ?>
              <td><strong><?= e($p['booking_name']) ?></strong><?php $others = array_values(array_filter($p['names'], fn($n) => strcasecmp($n, $p['booking_name']) !== 0)); ?>
                <?php if ($others): ?><div class="gi-names">+ <?= e(implode(', ', $others)) ?></div><?php endif; ?>
                <div class="gi-names"><?= (int)$p['adults'] ?> adult<?= $p['adults'] === 1 ? '' : 's' ?></div></td>
              <?php endif; ?>
              <td><?= e($p['head']) ?><div class="gi-names"><?= e($p['email']) ?></div></td>
              <td style="white-space:nowrap"><?= e($fmtD($p['check_in'])) ?> → <?= e($fmtD($p['check_out'])) ?></td>
              <td><?= $badge($p['status']) ?><?php if ($p['reason'] !== '' && $p['status'] !== 'ready'): ?><div class="gi-reason"><?= e($p['reason']) ?></div><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <form method="POST" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="create">
          <button type="submit" class="btn-primary" <?= ($ready + (int)($counts['imported'] ?? 0)) ? '' : 'disabled' ?>
                  <?php if ($ready): ?>data-confirm="Create <?= $ready ?> confirmed booking<?= $ready === 1 ? '' : 's' ?> for <?= e($gi['label']) ?>? No emails are sent."<?php endif; ?>><?= admin_icon('check', 15) ?> <?= $ready ? 'Create ' . $ready . ' booking' . ($ready === 1 ? '' : 's') : 'Show the links' ?></button></form>
        <form method="POST" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="cancel">
          <button type="submit" class="btn-outline">Start again</button></form>
      </div>
    </div>
  </div>

<?php else: ?>
  <div class="card">
    <div class="card__head"><span class="card__title"><?= admin_icon('download', 16) ?> Upload a group's room allocation</span></div>
    <div class="card__body" style="padding:20px">
      <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?><input type="hidden" name="action" value="preview">
        <div class="field" style="max-width:420px">
          <label for="giLabel">Group name</label>
          <input class="inp" id="giLabel" name="label" required maxlength="120" placeholder="e.g. Chris &amp; Bini wedding" value="<?= e((string)($_POST['label'] ?? '')) ?>">
        </div>
        <div class="field" style="margin-top:12px">
          <label>Allocation file <span class="text-muted">(.csv — columns: property, room, guests, head, email, check-in, check-out)</span></label>
          <label class="filefield">
            <span class="btn-outline btn-sm"><?= admin_icon('download', 14) ?> Choose file</span>
            <input type="file" name="sheet" accept=".csv,text/csv" data-gi-file>
            <span class="filefield__name" data-gi-filename>No file chosen</span>
          </label>
        </div>
        <button type="submit" class="btn-primary" style="margin-top:14px" data-gi-submit disabled><?= admin_icon('eye', 15) ?> Upload &amp; preview</button>
      </form>
      <ul class="text-muted" style="margin:18px 0 0;padding-left:18px;font-size:12.5px;line-height:1.7;max-width:80ch">
        <li>Each room becomes its own <strong>confirmed booking</strong> with a guest link (stay page + online check-in). Nothing is created until you press Create on the preview.</li>
        <li>Maya Ilai rooms are read from their names: “Studio No. 03A” = studio 3; “Villa 05: Room 501 / 502 / 503” = villa 5's first double / second double / bunk room — only that bedroom is booked.</li>
        <li>Other properties' room names are matched to a room on the preview, once.</li>
        <li>No price and no emails: the group is billed separately, and you send the links yourself.</li>
      </ul>
    </div>
  </div>

  <?php $batches = gi_batches(); if ($batches): ?>
  <div class="card" style="margin-top:16px">
    <div class="card__head"><span class="card__title"><?= admin_icon('link', 16) ?> Imported groups</span></div>
    <div class="card__body" style="padding:0">
      <table class="data-table" style="width:100%"><tbody>
        <?php foreach ($batches as $b): ?>
        <tr data-href="<?= e($self . '?batch=' . urlencode($b['slug'])) ?>"><td><a href="<?= e($self . '?batch=' . urlencode($b['slug'])) ?>"><?= e($b['label']) ?></a></td>
          <td class="text-muted"><?= (int)$b['count'] ?> booking<?= $b['count'] === 1 ? '' : 's' ?></td>
          <td class="text-muted"><?= $b['updated_at'] !== '' ? e(date('j M Y', strtotime($b['updated_at']))) : '' ?></td></tr>
        <?php endforeach; ?>
      </tbody></table>
    </div>
  </div>
  <?php endif; ?>
<?php endif; ?>

<script>
(function () {
  var inp = document.querySelector('[data-gi-file]');
  if (inp && !inp.dataset.giBound) {
    inp.dataset.giBound = '1';
    inp.addEventListener('change', function () {
      var f = inp.files && inp.files[0];
      var n = document.querySelector('[data-gi-filename]'), b = document.querySelector('[data-gi-submit]');
      if (n) n.textContent = f ? f.name : 'No file chosen';
      if (b) b.disabled = !f;
    });
  }
  document.querySelectorAll('[data-gi-copy]').forEach(function (btn) {
    if (btn.dataset.giBound) return;
    btn.dataset.giBound = '1';
    btn.addEventListener('click', function () {
      var t = btn.getAttribute('data-gi-copy') || '';
      var done = function () { var o = btn.innerHTML; btn.textContent = 'Copied ✓'; setTimeout(function () { btn.innerHTML = o; }, 1500); };
      if (navigator.clipboard) navigator.clipboard.writeText(t).then(done, function () {});
    });
  });
})();
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
