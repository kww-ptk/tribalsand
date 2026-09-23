<?php
/**
 * Admin: calendar highlights — owner or manager marks any date range with a
 * label (school holidays, Easter week, an event, peak season …). They show on
 * the Calendar (Gantt) and Timetable alongside the built-in Kenyan public
 * holidays, which stay automatic. Helpers: includes/calendar-highlights.php.
 * All forms are PRG + CSRF.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/calendar-highlights.php';
require_login();
require_manager();   // owner or manager

$pageTitle  = 'Calendar highlights';
$activeMenu = 'cal_highlights';
$supported  = cal_highlights_supported();

$flash = $_SESSION['calhl_flash'] ?? null;          unset($_SESSION['calhl_flash']);
$old   = $_SESSION['calhl_old'] ?? null;            unset($_SESSION['calhl_old']);
$errs  = $_SESSION['calhl_errors'] ?? [];           unset($_SESSION['calhl_errors']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported) {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'save') {
        $hid = (int)($_POST['id'] ?? 0);
        $r   = cal_highlight_save($hid, $_POST, (int)($_SESSION['admin_id'] ?? 0));
        if ($r['ok']) {
            audit_log($hid ? 'calendar.highlight_update' : 'calendar.highlight_create', 'calendar_highlight', $r['id'], trim((string)($_POST['label'] ?? '')));
            $_SESSION['calhl_flash'] = ['type' => 'success', 'msg' => $hid ? 'Highlight updated.' : 'Highlight added — it now shows on the Calendar and Timetable.'];
            header('Location: /admin/calendar-highlights.php'); exit;
        }
        $_SESSION['calhl_errors'] = $r['errors'];
        $_SESSION['calhl_old']    = array_intersect_key($_POST, array_flip(['id', 'label', 'date_from', 'date_to', 'color', 'note']));
        header('Location: /admin/calendar-highlights.php' . ($hid ? '?edit=' . $hid : '') . '#form'); exit;
    }
    if ($act === 'delete') {
        $hid = (int)($_POST['id'] ?? 0);
        if (cal_highlight_delete($hid)) {
            audit_log('calendar.highlight_delete', 'calendar_highlight', $hid, '');
            $_SESSION['calhl_flash'] = ['type' => 'success', 'msg' => 'Highlight deleted.'];
        } else {
            $_SESSION['calhl_flash'] = ['type' => 'error', 'msg' => 'That highlight no longer exists.'];
        }
        header('Location: /admin/calendar-highlights.php'); exit;
    }
}

$all   = fetch_all_cal_highlights();
$today = date('Y-m-d');
$upcoming = array_values(array_filter($all, fn($h) => (string)$h['date_to'] >= $today));
usort($upcoming, fn($a, $b) => strcmp((string)$a['date_from'], (string)$b['date_from']));
$past     = array_values(array_filter($all, fn($h) => (string)$h['date_to'] < $today));

// Form values: re-show a failed post, else the row being edited, else blank.
$editId = (int)($_GET['edit'] ?? 0);
$form   = ['id' => 0, 'label' => '', 'date_from' => '', 'date_to' => '', 'color' => 'amber', 'note' => ''];
if ($editId) {
    foreach ($all as $h) if ((int)$h['id'] === $editId) { $form = array_intersect_key($h, $form) + $form; break; }
}
if ($old) $form = array_merge($form, $old);
$isEdit = (int)$form['id'] > 0;

$year    = (int)date('Y');
$keYear  = ke_holidays_for_year($year);
ksort($keYear);

/** One highlight row in a list. */
function calhl_row(array $h): void { ?>
  <li class="chl-row">
    <span class="chl-sw chl-sw--<?= e($h['color']) ?>" aria-hidden="true"></span>
    <span class="chl-main">
      <span class="chl-name"><?= e($h['label']) ?></span>
      <span class="text-muted chl-meta"><?= e(cal_range_label((string)$h['date_from'], (string)$h['date_to'])) ?><?php
        $days = (int)round((strtotime((string)$h['date_to']) - strtotime((string)$h['date_from'])) / 86400) + 1;
        echo ' · ' . $days . ' day' . ($days === 1 ? '' : 's');
        if (!empty($h['note'])) echo ' · ' . e($h['note']); ?></span>
    </span>
    <span class="chl-act">
      <a href="/admin/calendar-highlights.php?edit=<?= (int)$h['id'] ?>#form" class="btn-icon" data-tip="Edit" aria-label="Edit <?= e($h['label']) ?>"><?= admin_icon('edit', 15) ?></a>
      <form method="POST" action="/admin/calendar-highlights.php" style="margin:0" onsubmit="return confirm('Delete this highlight?')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
        <button type="submit" class="btn-icon btn-icon--danger" data-tip="Delete" aria-label="Delete <?= e($h['label']) ?>"><?= admin_icon('trash', 15) ?></button>
      </form>
    </span>
  </li>
<?php }

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Calendar highlights</h1>
  <a href="/admin/gantt.php" class="btn-outline btn-sm"><?= admin_icon('calendar', 15) ?> Open calendar</a>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_calendar_highlights.sql</code> migration (Admin → Migrations) to add your own highlights. Kenyan public holidays already show on the calendars automatically.</div>
<?php else: ?>

<p class="text-muted" style="margin:-6px 0 16px;font-size:13px;max-width:760px">Mark any dates on the team calendars — school holidays, Easter week, an event, peak season. They show on the <strong>Calendar</strong> and <strong>Timetable</strong> next to the Kenyan public holidays, which are added automatically.</p>

<div class="chl-grid">
  <!-- Add / edit -->
  <div class="card" id="form">
    <div class="card__head"><span class="card__title"><?= $isEdit ? 'Edit highlight' : 'Add a highlight' ?></span></div>
    <div class="card__body" style="padding:18px">
      <?php if (!empty($errs['_'])): ?><div class="alert alert--error"><?= e($errs['_']) ?></div><?php endif; ?>
      <form method="POST" action="/admin/calendar-highlights.php" class="chl-form" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">

        <div class="field"><label for="chlLabel">Name</label>
          <input id="chlLabel" name="label" class="inp<?= isset($errs['label']) ? ' is-invalid' : '' ?>" maxlength="120" value="<?= e((string)$form['label']) ?>" placeholder="e.g. Kenya school holiday" style="width:100%" required>
          <?php if (isset($errs['label'])): ?><div class="field-error"><?= e($errs['label']) ?></div><?php endif; ?>
        </div>

        <div class="chl-dates">
          <div class="field"><label>First day</label>
            <button type="button" class="dp-btn" data-dp-target="chlFrom" data-dp-past data-dp-placeholder="First day" style="width:100%"><?= $form['date_from'] ? e(date('j M Y', strtotime((string)$form['date_from']))) : 'First day' ?></button>
            <input type="hidden" id="chlFrom" name="date_from" value="<?= e((string)$form['date_from']) ?>">
            <?php if (isset($errs['date_from'])): ?><div class="field-error"><?= e($errs['date_from']) ?></div><?php endif; ?>
          </div>
          <div class="field"><label>Last day <span class="text-muted">(blank = one day)</span></label>
            <button type="button" class="dp-btn" data-dp-target="chlTo" data-dp-past data-dp-placeholder="Last day" style="width:100%"><?= ($form['date_to'] && $form['date_to'] !== $form['date_from']) ? e(date('j M Y', strtotime((string)$form['date_to']))) : 'Last day' ?></button>
            <input type="hidden" id="chlTo" name="date_to" value="<?= e(($form['date_to'] && $form['date_to'] !== $form['date_from']) ? (string)$form['date_to'] : '') ?>">
            <?php if (isset($errs['date_to'])): ?><div class="field-error"><?= e($errs['date_to']) ?></div><?php endif; ?>
          </div>
        </div>

        <div class="field"><label>Colour on the calendar</label>
          <div class="chl-colors">
            <?php foreach (cal_highlight_colors() as $ck => $cn): ?>
            <label class="optchip"><input type="radio" name="color" value="<?= e($ck) ?>"<?= $form['color'] === $ck ? ' checked' : '' ?>><span class="chl-sw chl-sw--<?= e($ck) ?>" aria-hidden="true"></span> <?= e($cn) ?></label>
            <?php endforeach; ?>
          </div>
          <div class="text-muted" style="font-size:11.5px;margin-top:6px">Public holidays are always red.</div>
        </div>

        <div class="field"><label for="chlNote">Note <span class="text-muted">(optional)</span></label>
          <input id="chlNote" name="note" class="inp" maxlength="500" value="<?= e((string)$form['note']) ?>" placeholder="Shown in the list, e.g. “Expect family bookings”" style="width:100%">
        </div>

        <div class="chl-actions">
          <button type="submit" class="btn-primary btn-sm"><?= $isEdit ? 'Save changes' : 'Add highlight' ?></button>
          <?php if ($isEdit): ?><a href="/admin/calendar-highlights.php" class="btn-outline btn-sm">Cancel</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- Lists -->
  <div class="chl-col">
    <div class="card">
      <div class="card__head"><span class="card__title">Current &amp; upcoming</span></div>
      <div class="card__body" style="padding:<?= $upcoming ? '0' : '18px' ?>">
        <?php if (!$upcoming): ?>
          <p class="text-muted" style="font-size:13px;margin:0">Nothing coming up. Add your first highlight on the left.</p>
        <?php else: ?>
          <ul class="chl-list"><?php foreach ($upcoming as $h) calhl_row($h); ?></ul>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($past): ?>
    <details class="card chl-past">
      <summary class="card__head"><span class="card__title">Past (<?= count($past) ?>)</span><?= admin_icon('chevron-down', 15) ?></summary>
      <div class="card__body" style="padding:0"><ul class="chl-list"><?php foreach ($past as $h) calhl_row($h); ?></ul></div>
    </details>
    <?php endif; ?>

    <div class="card">
      <div class="card__head"><span class="card__title">Kenyan public holidays <?= $year ?> <span class="text-muted" style="font-weight:400;font-size:12px">· automatic</span></span></div>
      <div class="card__body" style="padding:0">
        <ul class="chl-list">
          <?php foreach ($keYear as $ymd => $name): ?>
          <li class="chl-row chl-row--ke<?= $ymd < $today ? ' is-past' : '' ?>">
            <span class="chl-sw chl-sw--red" aria-hidden="true"></span>
            <span class="chl-main"><span class="chl-name"><?= e($name) ?></span><span class="text-muted chl-meta"><?= e(date('D j M Y', strtotime($ymd))) ?></span></span>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<style>
.chl-grid{display:grid;grid-template-columns:minmax(300px,420px) 1fr;gap:16px;align-items:start}
@media (max-width:900px){.chl-grid{grid-template-columns:1fr}}
.chl-col{display:flex;flex-direction:column;gap:16px}
.chl-form{display:flex;flex-direction:column;gap:14px}
.chl-form .field > label{display:block;font-size:12px;color:var(--muted,#6b7280);margin-bottom:4px}
.chl-form .optchip{cursor:pointer}
.chl-dates{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width:420px){.chl-dates{grid-template-columns:1fr}}
.chl-colors{display:flex;flex-wrap:wrap;gap:6px}
.chl-actions{display:flex;gap:8px}
.field-error{color:var(--red,#b42318);font-size:12px;margin-top:4px}
.inp.is-invalid{border-color:var(--red,#b42318)}
.chl-list{list-style:none;margin:0;padding:0}
.chl-row{display:flex;align-items:center;gap:12px;padding:10px 16px;border-top:1px solid var(--border,#e7ded7)}
.chl-row:first-child{border-top:0}
.chl-row.is-past{opacity:.55}
.chl-main{flex:1 1 auto;min-width:0;display:flex;flex-direction:column;gap:2px}
.chl-name{font-weight:600;font-size:13.5px}
.chl-meta{font-size:12px}
.chl-act{display:flex;align-items:center;gap:2px}
.chl-sw{display:inline-block;width:12px;height:12px;border-radius:3px;flex:none;border:1px solid rgba(0,0,0,.1)}
.chl-sw--red{background:#fbd5d5;border-color:#f1a9b1}
.chl-sw--amber{background:#fde7b0;border-color:#f3c96a}
.chl-sw--green{background:#cdeccf;border-color:#94cf98}
.chl-sw--blue{background:#d3e4fb;border-color:#9dbff0}
.chl-sw--purple{background:#e6d9f7;border-color:#c4a8ea}
.optchip:has(input:checked) .chl-sw{border-color:#fff}
.chl-past summary{list-style:none;cursor:pointer;display:flex;justify-content:space-between;align-items:center}
.chl-past summary::-webkit-details-marker{display:none}
.chl-past[open] summary svg{transform:rotate(180deg)}
</style>

<?php include __DIR__ . '/_layout_end.php'; ?>
