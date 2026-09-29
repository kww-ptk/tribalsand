<?php
/**
 * Email log — every email the system sent (or decided not to send), who got it,
 * when, why, and whether it was delivered. Read-only.
 *
 * Owner sees everything; a manager sees the emails of their own properties
 * (email_log.venue_id, resolved when the email was sent). ?id=<n> shows one
 * email with the exact rendered body the recipient got.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_manager();

$scope     = admin_venue_ids();   // null = owner (everything)
$supported = email_log_supported();
$reg       = email_registry();

// ── One email ────────────────────────────────────────────────────────────
$viewId = (int)($_GET['id'] ?? 0);
if ($viewId > 0) {
    $row = $supported ? db_query(
        'SELECT l.*, a.name AS admin_name, v.name AS venue_name
           FROM email_log l LEFT JOIN admin_users a ON a.id = l.admin_id LEFT JOIN venues v ON v.id = l.venue_id
          WHERE l.id = :id AND ' . email_log_scope_sql($scope),
        [':id' => $viewId]
    )->fetch() : false;

    $pageTitle  = 'Email log';
    $activeMenu = 'email_log';
    include __DIR__ . '/_layout.php';
    if (!$row): ?>
      <div class="page-header"><h1>Email not found</h1></div>
      <div class="card"><div class="card__body" style="padding:18px">That email isn’t in the log, or it belongs to a property you don’t manage. <a href="/admin/email-log.php">Back to the log</a></div></div>
    <?php include __DIR__ . '/_layout_end.php'; exit; endif;

    $t = $reg[$row['template_key']] ?? null;
    [$lbl, $cls] = email_status_badge((string)$row['status']);
    $who = match ($row['triggered_by']) {
        'admin'  => ($row['admin_name'] ?: 'A team member') . ' (admin)',
        'pos'    => ($row['admin_name'] ?: 'A cashier') . ' (at the till)',
        'guest'  => 'The guest / a website visitor',
        default  => 'Automatic (the system)',
    };
    ?>
    <div class="page-header">
      <h1><?= e(email_template_name((string)$row['template_key'])) ?></h1>
      <a href="/admin/email-log.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 14) ?> Back to the log</a>
    </div>

    <div class="el-detail">
      <div class="card">
        <div class="card__head"><span class="card__title">What happened</span><span class="badge <?= $cls ?>"><?= e($lbl) ?></span></div>
        <div class="card__body" style="padding:18px">
          <dl class="el-dl">
            <dt>To</dt><dd><?= e($row['to_email'] ?: '—') ?> <span class="text-muted">(<?= $row['audience'] === 'staff' ? 'staff' : 'guest' ?>)</span></dd>
            <dt>Subject</dt><dd><?= e($row['subject'] ?: '—') ?></dd>
            <dt>When</dt><dd><?= e(date('D j M Y, H:i', strtotime((string)$row['created_at']))) ?></dd>
            <?php if (!empty($row['event_at'])): ?><dt>Last delivery update</dt><dd><?= e(date('D j M Y, H:i', strtotime((string)$row['event_at']))) ?></dd><?php endif; ?>
            <dt>Triggered by</dt><dd><?= e($who) ?></dd>
            <dt>From</dt><dd><code><?= e($row['trigger_source'] ?: '—') ?></code></dd>
            <?php if ($t): ?><dt>What sends this email</dt><dd><?= e($t['trigger']) ?></dd><?php endif; ?>
            <?php if (!empty($row['venue_name'])): ?><dt>Property</dt><dd><?= e($row['venue_name']) ?></dd><?php endif; ?>
            <?php if ($row['hold_id']): ?><dt>Booking</dt><dd><a href="/admin/booking.php?hold=<?= (int)$row['hold_id'] ?>">Booking #<?= (int)$row['hold_id'] ?></a></dd><?php endif; ?>
            <?php if ($row['submission_id']): ?><dt>Enquiry</dt><dd><a href="/admin/submission-view.php?id=<?= (int)$row['submission_id'] ?>">Enquiry #<?= (int)$row['submission_id'] ?></a></dd><?php endif; ?>
            <?php if ($row['reservation_id']): ?><dt>Table reservation</dt><dd><a href="/admin/reservations.php">Reservation #<?= (int)$row['reservation_id'] ?></a></dd><?php endif; ?>
            <?php if ($row['pos_sale_id']): ?><dt>Till sale</dt><dd><a href="/admin/pos-sales.php?sale=<?= (int)$row['pos_sale_id'] ?>">Sale #<?= (int)$row['pos_sale_id'] ?></a></dd><?php endif; ?>
            <?php if (($row['note'] ?? '') !== ''): ?><dt>Note</dt><dd><?= e($row['note']) ?></dd><?php endif; ?>
            <?php if (($row['error'] ?? '') !== ''): ?><dt>Error</dt><dd style="color:var(--red,#b42318)"><?= e($row['error']) ?></dd><?php endif; ?>
            <dt>Sent through</dt><dd><?= e(['smtp' => 'Amazon SES', 'log' => 'Dev log (not really sent)', 'mail' => 'PHP mail()', 'resend' => 'Resend'][$row['provider']] ?? '—') ?><?php if ($row['provider_id']): ?> <span class="text-muted" style="font-size:12px">· <?= e($row['provider_id']) ?></span><?php endif; ?></dd>
          </dl>
          <?php if (in_array($row['status'], ['suppressed', 'skipped'], true)): ?>
          <p class="text-muted" style="font-size:12.5px;margin:14px 0 0">This email was <strong>not sent</strong>. Below is what it would have said.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card__head"><span class="card__title">The email</span>
          <?php if (!empty($row['text_snapshot'])): ?><a href="#el-text" class="text-muted" style="font-size:12px" onclick="document.getElementById('el-text').open=true">Plain-text version</a><?php endif; ?>
        </div>
        <div class="card__body" style="padding:0">
          <?php if (!empty($row['html_snapshot'])): ?>
          <iframe class="el-frame" sandbox="" referrerpolicy="no-referrer" title="Email preview" srcdoc="<?= e((string)$row['html_snapshot']) ?>"></iframe>
          <?php elseif (!empty($row['text_snapshot'])): ?>
          <pre class="el-pre"><?= e((string)$row['text_snapshot']) ?></pre>
          <?php else: ?>
          <p class="text-muted" style="padding:18px;margin:0">The body is no longer kept (bodies are cleared after 180 days; the record stays).</p>
          <?php endif; ?>
          <?php if (!empty($row['html_snapshot']) && !empty($row['text_snapshot'])): ?>
          <details id="el-text" style="border-top:1px solid var(--border)"><summary style="padding:12px 18px;cursor:pointer;font-size:13px">Plain-text version</summary><pre class="el-pre"><?= e((string)$row['text_snapshot']) ?></pre></details>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <style>
    .el-detail{display:grid;grid-template-columns:minmax(0,380px) minmax(0,1fr);gap:16px;align-items:start}
    @media(max-width:1000px){.el-detail{grid-template-columns:minmax(0,1fr)}}
    .el-dl{display:grid;grid-template-columns:auto minmax(0,1fr);gap:8px 14px;margin:0;font-size:13.5px}
    .el-dl dt{color:var(--muted);white-space:nowrap}.el-dl dd{margin:0;overflow-wrap:anywhere}
    .el-frame{display:block;width:100%;height:760px;border:0;background:#f0f4f5;border-radius:0 0 12px 12px}
    .el-pre{white-space:pre-wrap;font-size:12.5px;padding:16px 18px;margin:0;overflow-x:auto}
    </style>
    <?php include __DIR__ . '/_layout_end.php';
    exit;
}

// ── The list ─────────────────────────────────────────────────────────────
$fKey    = (string)($_GET['template'] ?? '');
$fStatus = (string)($_GET['status'] ?? '');
$fAud    = (string)($_GET['audience'] ?? '');
$fFrom   = (string)($_GET['from'] ?? '');
$fTo     = (string)($_GET['to'] ?? '');
$fHold   = (int)($_GET['hold'] ?? 0);
if (!isset($reg[$fKey])) $fKey = '';
if (!in_array($fStatus, EMAIL_LOG_STATUSES, true) && $fStatus !== 'problems') $fStatus = '';
if (!in_array($fAud, ['guest', 'staff'], true)) $fAud = '';
$fFrom = preg_match('~^\d{4}-\d{2}-\d{2}$~', $fFrom) ? $fFrom : '';
$fTo   = preg_match('~^\d{4}-\d{2}-\d{2}$~', $fTo) ? $fTo : '';

$pg = paginate_params(25);
$params = [];
$conds  = [email_log_scope_sql($scope)];
if ($fKey !== '')    { $conds[] = 'l.template_key = :k'; $params[':k'] = $fKey; }
if ($fStatus === 'problems') { $conds[] = "l.status IN ('failed','bounced','complained')"; }
elseif ($fStatus !== '') { $conds[] = 'l.status = :st'; $params[':st'] = $fStatus; }
if ($fAud !== '')    { $conds[] = 'l.audience = :au'; $params[':au'] = $fAud; }
if ($fFrom !== '')   { $conds[] = 'l.created_at >= CAST(:fr AS date)'; $params[':fr'] = $fFrom; }
if ($fTo !== '')     { $conds[] = "l.created_at < (CAST(:to AS date) + interval '1 day')"; $params[':to'] = $fTo; }
if ($fHold > 0)      { $conds[] = 'l.hold_id = :hid'; $params[':hid'] = $fHold; }
$sw = search_where(['l.to_email', 'l.subject'], $pg['q'], $params);
if ($sw !== '') $conds[] = $sw;
$where = 'WHERE ' . implode(' AND ', $conds);

$total = 0; $rows = [];
if ($supported) {
    $total = (int) db_query("SELECT COUNT(*) FROM email_log l $where", $params)->fetchColumn();
    $meta  = paginate_meta($total, $pg['page'], $pg['per']);
    $rows  = db_query("SELECT l.id, l.template_key, l.audience, l.to_email, l.subject, l.status, l.note, l.error,
                              l.triggered_by, l.hold_id, l.submission_id, l.created_at, a.name AS admin_name, v.name AS venue_name
                         FROM email_log l LEFT JOIN admin_users a ON a.id = l.admin_id LEFT JOIN venues v ON v.id = l.venue_id
                         $where ORDER BY l.created_at DESC, l.id DESC
                         LIMIT {$meta['per']} OFFSET {$meta['offset']}", $params)->fetchAll();
} else {
    $meta = paginate_meta(0, 1, $pg['per']);
}
$filtered = $fKey !== '' || $fStatus !== '' || $fAud !== '' || $fFrom !== '' || $fTo !== '' || $fHold > 0 || $pg['q'] !== '';

ob_start(); ?>
<div class="card">
  <div class="card__body" style="padding:0">
    <?php if (!$supported): ?>
      <?php dt_empty('The email log isn’t switched on yet. Run the add_email_log migration (Admin → Migrations).', 'inbox'); ?>
    <?php elseif (!$rows): ?>
      <?php
        // Say when logging began — an empty log right after go-live is expected, not a fault.
        $firstAt = db_query('SELECT MIN(created_at) FROM email_log')->fetchColumn();
        $since   = $firstAt ? ' The log started on ' . date('j M Y, H:i', strtotime((string)$firstAt)) . ' — earlier emails can’t be shown.'
                            : ' Nothing has been sent since the log was switched on — every email from now on appears here.';
        dt_empty(($filtered ? 'No emails match your filters.' : 'No emails logged yet.') . $since, 'inbox');
      ?>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>When</th><th>Email</th><th>To</th><th>Why</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
          [$lbl, $cls] = email_status_badge((string)$r['status']);
          $why = match ($r['triggered_by']) {
              'admin', 'pos' => $r['admin_name'] ? 'By ' . $r['admin_name'] : 'By staff',
              'guest'  => 'Guest / website',
              default  => 'Automatic',
          };
        ?>
          <tr>
            <td style="white-space:nowrap;font-size:12.5px"><?= e(date('j M Y', strtotime((string)$r['created_at']))) ?><div class="text-muted"><?= e(date('H:i', strtotime((string)$r['created_at']))) ?></div></td>
            <td><a href="/admin/email-log.php?id=<?= (int)$r['id'] ?>"><strong><?= e(email_template_name((string)$r['template_key'])) ?></strong></a>
              <div class="text-muted" style="font-size:12px;max-width:380px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e((string)$r['subject']) ?></div></td>
            <td style="font-size:12.5px"><?= e((string)$r['to_email'] ?: '—') ?><div class="text-muted"><?= $r['audience'] === 'staff' ? 'Staff' : 'Guest' ?><?= $r['venue_name'] ? ' · ' . e($r['venue_name']) : '' ?></div></td>
            <td style="font-size:12.5px"><?= e($why) ?>
              <?php if ($r['hold_id']): ?><div><a href="/admin/booking.php?hold=<?= (int)$r['hold_id'] ?>" class="text-muted">Booking #<?= (int)$r['hold_id'] ?></a></div>
              <?php elseif ($r['submission_id']): ?><div><a href="/admin/submission-view.php?id=<?= (int)$r['submission_id'] ?>" class="text-muted">Enquiry #<?= (int)$r['submission_id'] ?></a></div><?php endif; ?></td>
            <td><span class="badge <?= $cls ?>"><?= e($lbl) ?></span>
              <?php if (($r['error'] ?? '') !== '' || ($r['note'] ?? '') !== ''): ?><div class="text-muted" style="font-size:11px;margin-top:3px;max-width:240px"><?= e((string)($r['error'] ?: $r['note'])) ?></div><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?php dt_pager($meta); ?>
  </div>
</div>
<?php
$dtBody = ob_get_clean();
if ($pg['ajax']) { echo $dtBody; exit; }

$byTopic = [];
foreach ($reg as $k => $t) $byTopic[($t['audience'] === 'staff' ? 'Staff · ' : 'Guest · ') . $t['topic']][$k] = $t['name'];

$pageTitle  = 'Email log';
$activeMenu = 'email_log';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Email log</h1>
  <span style="display:inline-flex;align-items:center;gap:12px">
    <span style="color:var(--muted);font-size:13px"><?= number_format($total) ?> email<?= $total !== 1 ? 's' : '' ?></span>
    <?php if (is_owner()): ?><a href="/admin/emails.php" class="btn-outline btn-sm"><?= admin_icon('settings', 14) ?> Manage emails</a><?php endif; ?>
  </span>
</div>
<p class="text-muted" style="margin:-6px 0 16px;font-size:13px;max-width:80ch">Every email the system sends — and every one it deliberately didn’t (switched off, or staff unticked “Email the guest”). Emails sent before the log was switched on aren’t listed: Amazon SES keeps no per-message history.</p>

<div class="dt" data-dt>
  <div class="dt-controls">
    <form method="GET" action="/admin/email-log.php" class="filters" id="elFilter">
      <input type="hidden" name="q"   value="<?= e($pg['q']) ?>">
      <input type="hidden" name="per" value="<?= (int)$pg['per'] ?>">
      <?php if ($fHold > 0): ?><input type="hidden" name="hold" value="<?= $fHold ?>"><?php endif; ?>
      <div class="filter-field">
        <span>Email</span>
        <select name="template" class="filter-select" aria-label="Filter by email" onchange="this.form.submit()">
          <option value="">All emails</option>
          <?php foreach ($byTopic as $grp => $opts): ?>
          <optgroup label="<?= e($grp) ?>">
            <?php foreach ($opts as $k => $n): ?><option value="<?= e($k) ?>" <?= $fKey === $k ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
          </optgroup>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-field">
        <span>Status</span>
        <select name="status" class="filter-select" aria-label="Filter by status" onchange="this.form.submit()">
          <option value="">Any status</option>
          <option value="problems" <?= $fStatus === 'problems' ? 'selected' : '' ?>>Problems (failed / bounced / spam)</option>
          <?php foreach (EMAIL_LOG_STATUSES as $s): ?><option value="<?= $s ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= e(email_status_badge($s)[0]) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="filter-field">
        <span>To</span>
        <select name="audience" class="filter-select" aria-label="Filter by audience" onchange="this.form.submit()">
          <option value="">Guests &amp; staff</option>
          <option value="guest" <?= $fAud === 'guest' ? 'selected' : '' ?>>Guests</option>
          <option value="staff" <?= $fAud === 'staff' ? 'selected' : '' ?>>Staff</option>
        </select>
      </div>
      <div class="filter-field">
        <span>From</span>
        <button type="button" class="dp-btn" data-dp-target="elFrom" data-dp-past data-dp-placeholder="Any date" style="width:140px"><?= $fFrom !== '' ? e(date('j M Y', strtotime($fFrom))) : 'Any date' ?></button>
        <input type="hidden" id="elFrom" name="from" value="<?= e($fFrom) ?>">
      </div>
      <div class="filter-field">
        <span>To</span>
        <button type="button" class="dp-btn" data-dp-target="elTo" data-dp-past data-dp-placeholder="Any date" style="width:140px"><?= $fTo !== '' ? e(date('j M Y', strtotime($fTo))) : 'Any date' ?></button>
        <input type="hidden" id="elTo" name="to" value="<?= e($fTo) ?>">
      </div>
      <?php if ($filtered): ?>
      <a href="/admin/email-log.php" class="btn-outline btn-sm" style="align-self:flex-end"><?= admin_icon('x', 14) ?> Clear</a>
      <?php endif; ?>
    </form>
    <?php dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Search recipient or subject…']); ?>
  </div>
  <div class="dt-body" data-dt-body><?= $dtBody ?></div>
</div>

<script>
(function(){
  var f = document.getElementById('elFilter');
  ['elFrom','elTo'].forEach(function(id){ var d = document.getElementById(id); if (d && f) d.addEventListener('change', function(){ f.submit(); }); });
})();
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
