<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/checkin.php';
require_login();
require_owner();

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $steps = [];
    foreach (array_keys(checkin_step_catalog()) as $key) {
        $steps[$key] = [
            'enabled'  => isset($_POST['enabled'][$key]),
            'required' => isset($_POST['required'][$key]),
        ];
    }
    set_setting('checkin_steps', json_encode($steps));
    set_setting('checkin_required_default', isset($_POST['required_default']) ? '1' : '0');
    set_setting('checkin_waiver_text', trim((string)($_POST['waiver_text'] ?? '')));
    // Windows are free-form HH:MM strings; checkin_arrival_flag() ignores anything
    // it cannot parse, and checkin_times() falls back to the default for a blank.
    foreach (['checkin_time_from','checkin_time_to','checkout_time_from','checkout_time_to'] as $__tk) {
        set_setting($__tk, trim((string)($_POST[$__tk] ?? '')));
    }
    set_setting('checkin_early_late_note', trim((string)($_POST['early_late_note'] ?? '')));
    set_setting('checkin_welcome', trim((string)($_POST['welcome'] ?? '')));
    audit_log('checkin.config_change', 'settings', 0, '');
    $_SESSION['hold_flash'] = ['type' => 'success', 'msg' => 'Check-in settings saved.'];
    header('Location: /admin/checkin-settings.php'); exit;
}
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']['msg']; unset($_SESSION['hold_flash']); }

$cfg      = checkin_config();
$default  = setting('checkin_required_default', '0') === '1';
$waiver   = setting('checkin_waiver_text', '');
$times    = checkin_times();   // defaults applied, so the inputs are never blank
$welcome  = setting('checkin_welcome', '');

// Time fields render as styled <select>s (auto-enhanced into a non-native
// dropdown with a Lucide chevron by admin-select.js) — no native time input,
// no native clock/spinner. Value is 24h "HH:MM"; label is 12h. A legacy
// off-step value (e.g. 14:15) is preserved as its own option so saving can
// never silently drop it.
$ci_time_opts = function (string $selected): string {
    $selected = trim($selected);
    $out = ''; $found = false;
    for ($m = 0; $m < 24 * 60; $m += 30) {
        $h = intdiv($m, 60); $mi = $m % 60;
        $val = sprintf('%02d:%02d', $h, $mi);
        $h12 = $h % 12 ?: 12;
        $lbl = sprintf('%d:%02d %s', $h12, $mi, $h < 12 ? 'AM' : 'PM');
        $sel = ($val === $selected) ? ' selected' : '';
        if ($sel !== '') $found = true;
        $out .= '<option value="' . $val . '"' . $sel . '>' . $lbl . '</option>';
    }
    if ($selected !== '' && !$found) {
        $out = '<option value="' . e($selected) . '" selected>' . e($selected) . '</option>' . $out;
    }
    return $out;
};
$ci_time_field = function (string $id, string $name, string $label, string $selected) use ($ci_time_opts): string {
    return '<div class="field">'
         . '<label for="' . e($id) . '">' . e($label) . '</label>'
         . '<select id="' . e($id) . '" name="' . e($name) . '" class="inp">' . $ci_time_opts($selected) . '</select>'
         . '</div>';
};

$pageTitle  = 'Check-in Settings';
$activeMenu = 'settings';
include __DIR__ . '/_layout.php';
?>
<div class="page-header"><h1>Settings</h1></div>
<nav class="tabs" aria-label="Settings sections">
  <a href="/admin/settings.php" class="tab-btn">General</a>
  <a href="/admin/checkin-settings.php" class="tab-btn is-active">Pre-Check-in</a>
</nav>
<?php if (!checkin_supported()): ?>
<div class="alert alert--error">Run the <code>add_checkin.sql</code> migration (Settings → Migrate) to enable check-in.</div>
<?php endif; ?>
<?php if ($flash): ?><div class="alert alert--success is-flash"><?= e($flash) ?></div><?php endif; ?>

<form method="POST" action="/admin/checkin-settings.php" data-shell-form>
  <?= csrf_field() ?>
  <div class="setting-row" style="margin-bottom:16px">
    <div class="setting-row__text">
      <span class="setting-row__title">Require check-in by default</span>
      <span class="setting-row__desc">New bookings automatically ask guests to complete pre-check-in before arrival. You can still toggle this per booking.</span>
    </div>
    <label class="toggle" style="flex:0 0 auto">
      <input type="checkbox" name="required_default" value="1" <?= $default ? 'checked' : '' ?>>
      <span class="toggle-slider"></span>
    </label>
  </div>

  <div class="card" style="margin-bottom:16px">
    <div class="card__head"><span class="card__title">Steps</span></div>
    <div class="card__body" style="padding:0">
      <table class="data-table">
        <thead><tr><th>Step</th><th style="text-align:center">Shown</th><th style="text-align:center">Required</th></tr></thead>
        <tbody>
        <?php foreach ($cfg as $key => $s): ?>
          <tr>
            <td><?= e($s['label']) ?></td>
            <td style="text-align:center"><label class="ckwrap" style="display:inline-flex"><input type="checkbox" name="enabled[<?= e($key) ?>]" value="1" <?= $s['enabled'] ? 'checked' : '' ?>><span class="ck"></span></label></td>
            <td style="text-align:center"><label class="ckwrap" style="display:inline-flex"><input type="checkbox" name="required[<?= e($key) ?>]" value="1" <?= $s['required'] ? 'checked' : '' ?>><span class="ck"></span></label></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card" style="margin-bottom:16px">
    <div class="card__head"><span class="card__title">Check-in &amp; check-out times</span></div>
    <div class="card__body card__body--pad">
      <p class="text-muted" style="margin:0 0 18px;font-size:13px">Shown to the guest during pre-check-in. A guest who tells us they will arrive outside the check-in window gets a warning explaining the room may not be ready — they are not blocked.</p>
      <div class="form-row" style="max-width:520px;margin-bottom:4px">
        <?= $ci_time_field('ciFrom', 'checkin_time_from', 'Check-in from', (string)$times['ci_from']) ?>
        <?= $ci_time_field('ciTo',   'checkin_time_to',   'Check-in until', (string)$times['ci_to']) ?>
      </div>
      <div class="form-row" style="max-width:520px">
        <?= $ci_time_field('coFrom', 'checkout_time_from', 'Check-out from', (string)$times['co_from']) ?>
        <?= $ci_time_field('coTo',   'checkout_time_to',   'Check-out by',   (string)$times['co_to']) ?>
      </div>
      <div class="field" style="max-width:520px;margin-bottom:0">
        <label for="ciNote">Early check-in / late check-out note</label>
        <textarea id="ciNote" name="early_late_note" rows="2" class="inp inp--area" style="width:100%;min-height:72px"><?= e($times['note']) ?></textarea>
      </div>
    </div>
  </div>

  <div class="card" style="margin-bottom:16px">
    <div class="card__head"><span class="card__title">Waiver / indemnity terms</span></div>
    <div class="card__body card__body--pad">
      <textarea name="waiver_text" rows="8" class="inp inp--area" style="width:100%;min-height:220px" placeholder="Indemnity and insurance waiver the guest agrees to…"><?= e($waiver) ?></textarea>
    </div>
  </div>

  <div class="card" style="margin-bottom:16px">
    <div class="card__head"><span class="card__title">Welcome copy (optional)</span></div>
    <div class="card__body card__body--pad">
      <textarea name="welcome" rows="3" class="inp inp--area" style="width:100%;min-height:96px" placeholder="Shown on the check-in landing screen."><?= e($welcome) ?></textarea>
    </div>
  </div>

  <button type="submit" class="btn-primary">Save settings</button>
</form>
<?php include __DIR__ . '/_layout_end.php'; ?>
