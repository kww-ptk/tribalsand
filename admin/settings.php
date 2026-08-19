<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();
require_owner();

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'export_holds') {
        require_once __DIR__ . '/../includes/booking.php';
        $holds = db_query(
            "SELECT h.*, u.name AS unit_name, r.name AS room_name, s.guest_phone
             FROM holds h
             JOIN units u ON u.id = h.unit_id
             JOIN rooms r ON r.id = u.room_id
             LEFT JOIN submissions s ON s.id = h.submission_id
             WHERE h.status IN ('pending','confirmed')
             ORDER BY h.check_in ASC"
        )->fetchAll();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="holds-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Reference', 'Status', 'Room', 'Unit', 'Guest Name', 'Email', 'Phone', 'Check-in', 'Check-out', 'Expires', 'Created']);
        foreach ($holds as $h) {
            $ref = make_guest_ref((int)$h['id']);
            fputcsv($out, [
                $ref ?: 'HOLD-' . $h['id'],
                $h['status'],
                $h['room_name'],
                $h['unit_name'],
                $h['guest_name'],
                $h['guest_email'],
                $h['guest_phone'] ?? '',
                $h['check_in'],
                $h['check_out'],
                $h['expires_at'] ?? '',
                $h['created_at'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }

    if ($action === 'save_general') {
        $form_mode            = in_array($_POST['form_mode'] ?? '', ['enquiry', 'availability']) ? $_POST['form_mode'] : 'enquiry';
        $notify_email         = trim($_POST['notify_email']         ?? '');
        $site_currency        = trim($_POST['site_currency']        ?? 'USD');
        $checkin_instructions = trim($_POST['checkin_instructions'] ?? '');
        $unit_assignment      = in_array($_POST['unit_assignment'] ?? '', ['auto', 'manual']) ? $_POST['unit_assignment'] : 'auto';

        if ($notify_email && !filter_var($notify_email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid notification email.';
        } else {
            set_setting('form_mode',            $form_mode);
            set_setting('notify_email',         $notify_email);
            set_setting('site_currency',        $site_currency);
            set_setting('checkin_instructions', $checkin_instructions);
            set_setting('unit_assignment',      $unit_assignment);
            audit_log('settings.save_general');
            $success = 'Settings saved.';
        }
    }

    if ($action === 'fx_sync') {
        $r = fx_sync_rates();
        if (!empty($r['ok'])) {
            audit_log('settings.fx_sync', '', 0, implode(',', $r['updated'] ?? []));
            $success = 'Exchange rates refreshed'
                     . (!empty($r['updated']) ? ' (' . implode(', ', $r['updated']) . ').' : '.');
        } else {
            $error = $r['message'] ?? 'Rate sync failed.';
        }
    }

    if ($action === 'save_fx_rates') {
        $postRates  = $_POST['rate']   ?? [];
        $postLocked = $_POST['locked'] ?? [];
        $rates  = fx_rates()['rates'];
        $locked = [];
        $bad    = false;
        foreach (array_keys(TS_CURRENCIES) as $c) {
            if ($c === 'USD') { $rates['USD'] = 1.0; continue; } // base fixed
            $v = (float) str_replace(',', '', (string)($postRates[$c] ?? ''));
            if ($v > 0) { $rates[$c] = $v; } else { $bad = true; }
            if (!empty($postLocked[$c])) $locked[$c] = true;
        }
        if ($bad) {
            $error = 'Every exchange rate must be a positive number.';
        } else {
            fx_save($rates, $locked);
            audit_log('settings.save_fx_rates');
            $success = 'Exchange rates saved.';
        }
    }

    if ($action === 'change_password') {
        $current  = $_POST['current_password']  ?? '';
        $new      = $_POST['new_password']       ?? '';
        $confirm  = $_POST['confirm_password']   ?? '';

        $admin = current_admin();
        $user  = db_query('SELECT * FROM admin_users WHERE id = :id', [':id' => $admin['id']])->fetch();

        if (!password_verify($current, $user['password_hash'])) {
            $error = 'Current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $error = 'New passwords do not match.';
        } else {
            db_query(
                'UPDATE admin_users SET password_hash = :hash WHERE id = :id',
                [':hash' => password_hash($new, PASSWORD_BCRYPT), ':id' => $admin['id']]
            );
            $success = 'Password changed successfully.';
        }
    }
}

$form_mode            = setting('form_mode',            'enquiry');
$notify_email         = setting('notify_email',         '');
$site_currency        = setting('site_currency',        'USD');
$checkin_instructions = setting('checkin_instructions', '');
$unit_assignment      = setting('unit_assignment',      'auto');

$fx           = fx_rates(true); // fresh read so a just-saved/synced value shows immediately
$fx_rates_map = $fx['rates']  ?? [];
$fx_locked    = is_array($fx['locked'] ?? null) ? $fx['locked'] : [];
$fx_fetched   = $fx['fetched_at'] ?? null;

$pageTitle  = 'Settings';
$activeMenu = 'settings';
include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Settings</h1>
</div>

<nav class="tabs" aria-label="Settings sections">
  <a href="/admin/settings.php" class="tab-btn is-active">General</a>
  <a href="/admin/checkin-settings.php" class="tab-btn">Pre-Check-in</a>
</nav>

<?php if ($success): ?><div class="alert alert--success is-flash"><?= e($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert--error is-flash"><?= e($error) ?></div><?php endif; ?>

<!-- General settings -->
<div class="card">
  <div class="card__head"><span class="card__title">General</span></div>
  <div class="card__body" style="padding:20px">
    <form method="POST" action="/admin/settings" data-shell-form>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_general">

      <div class="form-section">
        <div class="form-section__title">Booking Form Mode</div>
        <p style="font-size:13px;color:var(--muted);margin-bottom:14px">
          The default for any room set to <strong>"Use global setting"</strong> (in Rooms &rarr; Edit).
          Rooms with their own mode set ignore this. Changes take effect immediately on the public site.
        </p>
        <label style="display:flex;align-items:flex-start;gap:12px;margin-bottom:12px;cursor:pointer">
          <input type="radio" name="form_mode" value="enquiry" <?= $form_mode==='enquiry'?'checked':'' ?> style="margin-top:3px">
          <div>
            <strong>Enquiry mode</strong>
            <div style="font-size:12.5px;color:var(--muted)">Guests fill in a form — name, dates, message. Goes to your inbox.</div>
          </div>
        </label>
        <label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer">
          <input type="radio" name="form_mode" value="availability" <?= $form_mode==='availability'?'checked':'' ?> style="margin-top:3px">
          <div>
            <strong>Availability mode</strong> <span class="badge badge--orange" style="font-size:10px">v2</span>
            <div style="font-size:12.5px;color:var(--muted)">Shows the live booking calendar — guests pick open dates and the dates are held for 24 hours.</div>
          </div>
        </label>
      </div>

      <div class="form-section">
        <div class="form-section__title">Notifications</div>
        <div class="field">
          <label>Notification email</label>
          <input type="email" name="notify_email" value="<?= e($notify_email) ?>"
                 placeholder="reservation@tribalsand.com">
          <span class="field-hint">All form submissions send a notification to this address.</span>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section__title">Currency</div>
        <?php
          $__ccys = [
            'USD'=>'USD — US Dollar', 'EUR'=>'EUR — Euro', 'GBP'=>'GBP — British Pound',
            'KES'=>'KES — Kenyan Shilling', 'TZS'=>'TZS — Tanzanian Shilling', 'UGX'=>'UGX — Ugandan Shilling',
            'ZAR'=>'ZAR — South African Rand', 'AED'=>'AED — UAE Dirham', 'CHF'=>'CHF — Swiss Franc',
            'AUD'=>'AUD — Australian Dollar', 'CAD'=>'CAD — Canadian Dollar', 'JPY'=>'JPY — Japanese Yen',
            'CNY'=>'CNY — Chinese Yuan', 'INR'=>'INR — Indian Rupee',
          ];
          // Keep an unlisted stored code selectable so saving never loses it.
          if ($site_currency !== '' && !isset($__ccys[$site_currency])) $__ccys = [$site_currency=>$site_currency] + $__ccys;
        ?>
        <div class="field" style="max-width:280px">
          <label for="site_currency">Default currency code</label>
          <select id="site_currency" name="site_currency" class="inp">
            <?php foreach ($__ccys as $code => $lbl): ?>
            <option value="<?= e($code) ?>" <?= $site_currency === $code ? 'selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section__title">Check-in Instructions</div>
        <p style="font-size:13px;color:var(--muted);margin-bottom:14px">
          Sent to guests in the booking confirmation email. Leave blank to omit.
        </p>
        <div class="field">
          <textarea name="checkin_instructions" rows="5" class="inp inp--area" style="width:100%;min-height:120px" placeholder="e.g. Check-in is from 14:00. Our reception is open 24 hours. Please WhatsApp us your flight details 48 hours before arrival..."><?= e($checkin_instructions) ?></textarea>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section__title">Unit Assignment</div>
        <p style="font-size:13px;color:var(--muted);margin-bottom:14px">
          Controls how a physical unit is assigned when a hold is created.
        </p>
        <label style="display:flex;align-items:flex-start;gap:12px;margin-bottom:12px;cursor:pointer">
          <input type="radio" name="unit_assignment" value="auto" <?= $unit_assignment==='auto'?'checked':'' ?> style="margin-top:3px">
          <div>
            <strong>Auto</strong>
            <div style="font-size:12.5px;color:var(--muted)">First available unit is assigned automatically at request time.</div>
          </div>
        </label>
        <label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer">
          <input type="radio" name="unit_assignment" value="manual" <?= $unit_assignment==='manual'?'checked':'' ?> style="margin-top:3px">
          <div>
            <strong>Manual</strong>
            <div style="font-size:12.5px;color:var(--muted)">Admin selects the unit from the Holds page before confirming.</div>
          </div>
        </label>
      </div>

      <button type="submit" class="btn-primary">Save Settings</button>
    </form>
  </div>
</div>

<!-- Display currency rates -->
<div class="card">
  <div class="card__head"><span class="card__title">Display Currency Rates</span></div>
  <div class="card__body" style="padding:20px">
    <p style="font-size:13px;color:var(--muted);margin-bottom:8px">
      Rates guests see when they switch currency on the public site. <strong>Display only</strong> — every booking still settles in the property's real price. Base is USD.
    </p>
    <p style="font-size:12px;color:var(--muted);margin-bottom:16px">
      Last updated: <strong><?= $fx_fetched ? e(date('j M Y, H:i', strtotime($fx_fetched))) : 'never — using built-in seed rates' ?></strong>
    </p>

    <form method="POST" action="/admin/settings" style="margin-bottom:18px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_fx_rates">
      <table style="border-collapse:collapse;margin-bottom:16px">
        <thead><tr>
          <th style="text-align:left;padding:6px 16px 8px 0;font-size:12px;color:var(--muted)">Currency</th>
          <th style="text-align:left;padding:6px 16px 8px;font-size:12px;color:var(--muted)">Rate (per 1 USD)</th>
          <th style="text-align:center;padding:6px 16px 8px;font-size:12px;color:var(--muted)">Lock</th>
        </tr></thead>
        <tbody>
        <?php foreach (TS_CURRENCIES as $code => $meta): ?>
          <tr>
            <td style="padding:6px 16px 6px 0"><strong><?= e($code) ?></strong> <span class="text-muted" style="font-size:12px"><?= e($meta['name']) ?></span></td>
            <td style="padding:6px 16px">
              <?php if ($code === 'USD'): ?>
                <input type="text" value="1.0000" class="inp" style="width:130px" disabled>
              <?php else: $__rv = rtrim(rtrim(number_format((float)($fx_rates_map[$code] ?? 0), 4, '.', ''), '0'), '.'); ?>
                <input type="text" name="rate[<?= e($code) ?>]" value="<?= e($__rv) ?>" class="inp" style="width:130px" inputmode="decimal">
              <?php endif; ?>
            </td>
            <td style="padding:6px 16px;text-align:center">
              <?php if ($code === 'USD'): ?>
                <span class="text-muted">—</span>
              <?php else: ?>
                <input type="checkbox" name="locked[<?= e($code) ?>]" value="1" <?= !empty($fx_locked[$code]) ? 'checked' : '' ?>>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <button type="submit" class="btn-primary">Save Rates</button>
      <span class="field-hint" style="display:block;margin-top:10px">A <strong>locked</strong> rate is never overwritten by the daily auto-sync — use it to pin a rate you set by hand.</span>
    </form>

    <form method="POST" action="/admin/settings" style="border-top:1px solid var(--border);padding-top:16px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="fx_sync">
      <button type="submit" class="btn-outline">Sync now — fetch latest rates</button>
      <span class="field-hint" style="display:block;margin-top:10px">Pulls live rates from open.er-api.com. Unlocked currencies update; locked ones stay as set.</span>
    </form>
  </div>
</div>

<!-- Change password -->
<div class="card">
  <div class="card__head"><span class="card__title">Change Password</span></div>
  <div class="card__body" style="padding:20px">
    <form method="POST" action="/admin/settings" data-shell-form style="max-width:400px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="change_password">

      <div class="field">
        <label>Current password</label>
        <input type="password" name="current_password" required placeholder="••••••••">
      </div>
      <div class="field">
        <label>New password <span class="text-muted">(min 8 characters)</span></label>
        <input type="password" name="new_password" required placeholder="••••••••" minlength="8">
      </div>
      <div class="field">
        <label>Confirm new password</label>
        <input type="password" name="confirm_password" required placeholder="••••••••">
      </div>

      <button type="submit" class="btn-primary">Change Password</button>
    </form>
  </div>
</div>

<!-- Data export -->
<div class="card">
  <div class="card__head"><span class="card__title">Data Export</span></div>
  <div class="card__body" style="padding:20px">
    <p style="font-size:13px;color:var(--muted);margin-bottom:16px">Download a CSV of all pending and confirmed holds, including guest contact details and dates.</p>
    <form method="POST" action="/admin/settings">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="export_holds">
      <button type="submit" class="btn-outline"><?= admin_icon('download') ?> Export Active Holds (CSV)</button>
    </form>
  </div>
</div>

<!-- System info -->
<div class="card">
  <div class="card__head"><span class="card__title">System Info</span></div>
  <div class="card__body" style="padding:20px">
    <div class="detail-grid">
      <div>
        <div class="detail-item__label">PHP Version</div>
        <div class="detail-item__value"><?= e(PHP_VERSION) ?></div>
      </div>
      <div>
        <div class="detail-item__label">Current form mode</div>
        <div class="detail-item__value">
          <span class="badge <?= $form_mode==='enquiry'?'badge--green':'badge--orange' ?>"><?= e($form_mode) ?></span>
        </div>
      </div>
      <div>
        <div class="detail-item__label">Notify email</div>
        <div class="detail-item__value"><?= e($notify_email ?: '—') ?></div>
      </div>
      <div>
        <div class="detail-item__label">Currency</div>
        <div class="detail-item__value"><?= e($site_currency) ?></div>
      </div>
      <div>
        <div class="detail-item__label">Unit assignment</div>
        <div class="detail-item__value"><?= e($unit_assignment) ?></div>
      </div>
      <div>
        <div class="detail-item__label">Check-in instructions</div>
        <div class="detail-item__value" style="white-space:pre-wrap;font-size:12px"><?= e($checkin_instructions ?: '—') ?></div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
