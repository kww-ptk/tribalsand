<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/checkin.php';
require_login();
require_owner();

$checkin_default = checkin_supported() && setting('checkin_required_default', '0') === '1';
$want_checkin    = ($_SERVER['REQUEST_METHOD'] === 'POST') ? isset($_POST['require_checkin']) : $checkin_default;

$error = '';
$old   = ['unit_id' => '', 'check_in' => '', 'check_out' => '', 'guest_name' => '', 'guest_email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    verify_csrf();
    $str      = fn($v) => is_scalar($v) ? trim((string)$v) : '';
    $unit_id  = (int)$str($_POST['unit_id'] ?? '');
    $check_in = $str($_POST['check_in']  ?? '');
    $check_out= $str($_POST['check_out'] ?? '');
    $g_name   = $str($_POST['guest_name']  ?? '');
    $g_email  = $str($_POST['guest_email'] ?? '');
    $old = ['unit_id' => $unit_id ?: '', 'check_in' => $check_in, 'check_out' => $check_out,
            'guest_name' => $g_name, 'guest_email' => $g_email];

    $is_date  = fn($d) => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    $unit_ok  = $unit_id > 0 && db_query("SELECT 1 FROM units WHERE id = :id AND is_active = TRUE", [':id' => $unit_id])->fetchColumn();

    if (!$unit_ok)                                        $error = 'Please choose a valid room / unit.';
    elseif (!$is_date($check_in) || !$is_date($check_out)) $error = 'Please enter valid check-in and check-out dates.';
    elseif ($check_in >= $check_out)                     $error = 'Check-out must be after check-in.';
    elseif ($g_name === '')                              $error = 'Guest name is required.';
    elseif (!filter_var($g_email, FILTER_VALIDATE_EMAIL)) $error = 'A valid guest email is required.';

    if (!$error) {
        try {
            $hold_id = create_hold_with_block($unit_id, null, $check_in, $check_out, $g_name, $g_email, 'confirmed');
        } catch (Throwable $e) {
            error_log('[hold-new] create failed: ' . $e->getMessage());
            $error = 'Could not create the booking. Please try again.';
        }
        if (!$error) {
            if (checkin_supported() && $want_checkin) {
                db_query('UPDATE holds SET require_checkin = TRUE WHERE id = :id', [':id' => $hold_id]);
            }
            $code = (string)db_query("SELECT access_code FROM holds WHERE id = :id", [':id' => $hold_id])->fetchColumn();
            try {
                audit_log('hold.create_manual', 'hold', $hold_id, "manual booking — {$g_name} {$check_in}→{$check_out}");
            } catch (Throwable $e) { error_log('[hold-new] audit failed: ' . $e->getMessage()); }
            $_SESSION['hold_flash'] = ['type' => 'success', 'msg' => "Booking #{$hold_id} created for {$g_name} — code {$code}."];
            header('Location: /admin/holds.php'); exit;
        }
    }
}

$ru_options = fetch_room_unit_options();

$pageTitle  = 'New Booking';
$activeMenu = 'holds';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>New Booking</h1>
  <div class="actions"><a href="/admin/holds.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Holds</a></div>
</div>

<?php if ($error): ?>
<div class="card" style="border-left:4px solid var(--red,#dc2626);margin-bottom:16px">
  <div class="card__body" style="padding:14px 18px;font-size:14px"><?= e($error) ?></div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card__head"><span class="card__title">Create a confirmed booking</span></div>
  <div class="card__body" style="padding:20px">
    <?php if (!$ru_options): ?>
      <p style="margin:0;color:var(--muted)">No availability units exist yet — add units to a room first (Rooms admin).</p>
    <?php else: ?>
    <p style="margin:0 0 16px;font-size:13px;color:var(--muted)">Creates a confirmed booking, generates the guest's login code, and blocks the dates. Availability is not checked — you control overlaps.</p>
    <form method="POST" action="/admin/hold-new.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="detail-grid">
        <div>
          <div class="detail-item__label">Room / Unit</div>
          <select name="unit_id" required style="width:100%;padding:9px;border:1px solid #d1d5db;border-radius:6px">
            <option value="">— select —</option>
            <?php foreach ($ru_options as $o): ?>
            <option value="<?= (int)$o['unit_id'] ?>" <?= (int)$o['unit_id'] === (int)$old['unit_id'] ? 'selected' : '' ?>>
              <?= e($o['room_name']) ?> — <?= e($o['unit_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <div class="detail-item__label">Check-in</div>
          <button type="button" class="dp-btn" data-dp-role="ci" data-dp-pair="hnDates" data-dp-target="hnCheckin" data-dp-placeholder="Select check-in date">Select check-in date</button>
          <input type="hidden" id="hnCheckin" name="check_in" value="<?= e($old['check_in']) ?>">
        </div>
        <div>
          <div class="detail-item__label">Check-out</div>
          <button type="button" class="dp-btn" data-dp-role="co" data-dp-pair="hnDates" data-dp-target="hnCheckout" data-dp-placeholder="Select check-out date">Select check-out date</button>
          <input type="hidden" id="hnCheckout" name="check_out" value="<?= e($old['check_out']) ?>">
        </div>
        <div>
          <div class="detail-item__label">Guest name</div>
          <input type="text" name="guest_name" required value="<?= e($old['guest_name']) ?>" placeholder="Enter guest name"
                 style="width:100%;padding:9px;border:1px solid #d1d5db;border-radius:6px">
        </div>
        <div>
          <div class="detail-item__label">Guest email</div>
          <input type="email" name="guest_email" required value="<?= e($old['guest_email']) ?>" placeholder="Enter guest email"
                 style="width:100%;padding:9px;border:1px solid #d1d5db;border-radius:6px">
        </div>
        <?php if (checkin_supported()): ?>
        <div style="grid-column:1/-1">
          <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer">
            <input type="checkbox" name="require_checkin" value="1" <?= $want_checkin ? 'checked' : '' ?>>
            Require this guest to complete Pre-Check-in before using the portal
          </label>
        </div>
        <?php endif; ?>
      </div>
      <button type="submit" class="btn-primary btn-sm" style="margin-top:16px"
              onclick="return confirm('Create this confirmed booking?')">Create Booking</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
