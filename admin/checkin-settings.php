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

$pageTitle  = 'Check-in Settings';
$activeMenu = 'settings';
include __DIR__ . '/_layout.php';
?>
<div class="page-header"><h1>Pre-Check-in Settings</h1></div>
<?php if (!checkin_supported()): ?>
<div class="alert alert--error">Run the <code>add_checkin.sql</code> migration (Settings → Migrate) to enable check-in.</div>
<?php endif; ?>
<?php if ($flash): ?><div class="alert alert--success is-flash"><?= e($flash) ?></div><?php endif; ?>

<form method="POST" action="/admin/checkin-settings.php">
  <?= csrf_field() ?>
  <div class="card" style="margin-bottom:16px"><div class="card__body">
    <label style="display:flex;align-items:center;gap:8px;font-weight:600">
      <input type="checkbox" name="required_default" value="1" <?= $default ? 'checked' : '' ?>>
      Require check-in by default on new bookings
    </label>
  </div></div>

  <div class="card" style="margin-bottom:16px">
    <div class="card__head"><span class="card__title">Steps</span></div>
    <div class="card__body" style="padding:0">
      <table class="data-table">
        <thead><tr><th>Step</th><th style="text-align:center">Shown</th><th style="text-align:center">Required</th></tr></thead>
        <tbody>
        <?php foreach ($cfg as $key => $s): ?>
          <tr>
            <td><?= e($s['label']) ?></td>
            <td style="text-align:center"><input type="checkbox" name="enabled[<?= e($key) ?>]" value="1" <?= $s['enabled'] ? 'checked' : '' ?>></td>
            <td style="text-align:center"><input type="checkbox" name="required[<?= e($key) ?>]" value="1" <?= $s['required'] ? 'checked' : '' ?>></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card" style="margin-bottom:16px">
    <div class="card__head"><span class="card__title">Check-in &amp; check-out times</span></div>
    <div class="card__body">
      <p class="text-muted" style="margin:0 0 14px;font-size:13px">Shown to the guest during pre-check-in. A guest who tells us they will arrive outside the check-in window gets a warning explaining the room may not be ready — they are not blocked.</p>
      <div class="form-row" style="max-width:520px">
        <div class="field">
          <label for="ciFrom">Check-in from</label>
          <input id="ciFrom" type="time" name="checkin_time_from" class="inp" value="<?= e($times['ci_from']) ?>" style="width:100%">
        </div>
        <div class="field">
          <label for="ciTo">Check-in until</label>
          <input id="ciTo" type="time" name="checkin_time_to" class="inp" value="<?= e($times['ci_to']) ?>" style="width:100%">
        </div>
      </div>
      <div class="form-row" style="max-width:520px">
        <div class="field">
          <label for="coFrom">Check-out from</label>
          <input id="coFrom" type="time" name="checkout_time_from" class="inp" value="<?= e($times['co_from']) ?>" style="width:100%">
        </div>
        <div class="field">
          <label for="coTo">Check-out by</label>
          <input id="coTo" type="time" name="checkout_time_to" class="inp" value="<?= e($times['co_to']) ?>" style="width:100%">
        </div>
      </div>
      <div class="field" style="max-width:520px">
        <label for="ciNote">Early check-in / late check-out note</label>
        <textarea id="ciNote" name="early_late_note" rows="2" class="inp" style="width:100%;font-family:inherit"><?= e($times['note']) ?></textarea>
      </div>
    </div>
  </div>

  <div class="card" style="margin-bottom:16px">
    <div class="card__head"><span class="card__title">Waiver / indemnity terms</span></div>
    <div class="card__body">
      <textarea name="waiver_text" rows="8" style="width:100%;font-family:inherit;padding:10px" placeholder="Indemnity and insurance waiver the guest agrees to…"><?= e($waiver) ?></textarea>
    </div>
  </div>

  <div class="card" style="margin-bottom:16px">
    <div class="card__head"><span class="card__title">Welcome copy (optional)</span></div>
    <div class="card__body">
      <textarea name="welcome" rows="3" style="width:100%;font-family:inherit;padding:10px" placeholder="Shown on the check-in landing screen."><?= e($welcome) ?></textarea>
    </div>
  </div>

  <button type="submit" class="btn-primary">Save settings</button>
</form>
<?php include __DIR__ . '/_layout_end.php'; ?>
